<?php

/**
 * TPrivacyModule class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Rights;

use belisoful\Privacy\Retention\IProcessingActivityProvider;
use belisoful\Privacy\Retention\TLegalBasis;
use belisoful\Privacy\Retention\TProcessingActivity;
use belisoful\Privacy\Retention\TRetentionModule;
use belisoful\Privacy\TConfiguredComponentTrait;
use Prado\Exceptions\TConfigurationException;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\Prado;
use Prado\TModule;
use Prado\TPropertyValue;
use Prado\Util\Clock\TApplicationClockAwareTrait;
use Prado\Util\Log\TLogger;
use Prado\Xml\TXmlElement;

/**
 * TPrivacyModule class.
 *
 * TPrivacyModule answers data subject requests across the application: it collects the
 * `IPersonalDataProvider`s, runs an export, an erasure or a rectification across all of them, and
 * records each request and its outcome.
 *
 * ```xml
 * <module id="privacy-rights" class="belisoful\Privacy\Rights\TPrivacyModule" ResponseDays="30">
 *   <provider class="belisoful\Privacy\Rights\TUserPersonalDataProvider" />
 *   <provider class="belisoful\Privacy\Rights\TConsentPersonalDataProvider" />
 * </module>
 * ```
 *
 * ```php
 * // After the application verified the requester's identity:
 * $json = $privacy->exportJson(new TDataSubject('alice'));
 * $results = $privacy->erase(new TDataSubject('alice'), 'operator:bob');
 * ```
 *
 * Providers come from `<provider>` elements, `addProvider()`, and, with `AutoDiscover` (the
 * default), every loaded application module that implements `IPersonalDataProvider`. A provider
 * that throws does not stop the others: the request completes as `partial` (or `failed` when every
 * provider threw), and the exception is logged without being stored.
 *
 * Every request is recorded in the request log (`LogRequests`, `RequestLog`, `TDbPrivacyRequestLog`
 * on the runtime sqlite database by default) with its due date (`ResponseDays` after the request:
 * 30 by default; GDPR Art. 12(3) allows one month, CCPA 45 days). {@see openRequest()} records a
 * request received outside the application (by mail, by phone) so it counts toward the deadline;
 * {@see getOverdueRequests()} lists the late ones.
 *
 * With a `TShellApplication`, the module registers `prado-cli privacy/export`, `privacy/erase` and
 * `privacy/requests`.
 *
 * The module does not verify identity: the caller verifies that the requester is the subject or
 * acts for them, before building the `TDataSubject`.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TPrivacyModule extends TModule implements IProcessingActivityProvider
{
	use TApplicationClockAwareTrait;
	use TConfiguredComponentTrait;

	/** The request type of access and portability. */
	public const EXPORT = 'export';

	/** The request type of erasure. */
	public const ERASE = 'erase';

	/** The request type of rectification. */
	public const RECTIFY = 'rectify';

	/** Every provider answered. */
	public const STATUS_COMPLETED = 'completed';

	/** Some providers failed. */
	public const STATUS_PARTIAL = 'partial';

	/** Every provider failed. */
	public const STATUS_FAILED = 'failed';

	/** The shell action the module registers. */
	public const SHELL_CLASS = TPrivacyShellAction::class;

	/** @var array<int, mixed> the configured providers, or their configuration until first use */
	private array $_providers = [];

	/** @var bool whether loaded modules implementing IPersonalDataProvider join */
	private bool $_autoDiscover = true;

	/** @var mixed the request log, or its configuration until first use */
	private mixed $_requestLog = null;

	/** @var bool whether requests are recorded */
	private bool $_logRequests = true;

	/** @var int days to answer a request */
	private int $_responseDays = 30;

	/**
	 * Reads `<provider>` and `<requestlog>` elements (or the `providers` and `requestlog` keys of a
	 * PHP configuration) and registers the shell action in a shell application.
	 * @param null|array|TXmlElement $config the module configuration
	 */
	public function init($config)
	{
		if ($config instanceof TXmlElement) {
			foreach ($config->getElementsByTagName('provider') as $element) {
				$this->addProvider($element->getAttributes()->toArray());
			}
			if (($element = $config->getElementByTagName('requestlog')) !== null) {
				$this->setRequestLog($element->getAttributes()->toArray());
			}
		} elseif (\is_array($config)) {
			foreach ($config['providers'] ?? [] as $provider) {
				$this->addProvider($provider);
			}
			if (isset($config['requestlog'])) {
				$this->setRequestLog($config['requestlog']);
			}
		}
		$this->registerShellAction();
		parent::init($config);
	}

	/**
	 * Registers `prado-cli privacy/*` with a shell application, once.
	 * @return bool whether the action was registered now
	 */
	public function registerShellAction(): bool
	{
		return TPrivacyShellAction::register($this);
	}

	/**
	 * @return TProcessingActivity[] answering data subject requests, and keeping the record of them
	 */
	public function getProcessingActivities(): array
	{
		return [new TProcessingActivity([
			'Name' => 'privacy-requests',
			'Purpose' => 'Answering data subject requests (access, portability, erasure, rectification) and showing they were answered',
			'LegalBasis' => TLegalBasis::LegalObligation,
			'LegalBasisNote' => 'GDPR Art. 5(2), 12 to 17 and 20; CCPA/CPRA §1798.100 to .106',
			'SubjectCategories' => 'data subjects who make a request',
			'DataCategories' => 'request type, hashed user name, requester, dates, outcome per provider',
			'RetentionPolicy' => $this->getLogRequests() ? TRetentionModule::PRIVACY_REQUESTS : '',
			'RetentionPeriod' => $this->getLogRequests() ? '' : 'not recorded',
		])];
	}

	// --- providers ---------------------------------------------------------------------------

	/**
	 * Adds a provider.
	 * @param mixed $provider a provider, a configuration array (`class` and properties), or a module id
	 * @return static the module
	 */
	public function addProvider(mixed $provider): static
	{
		$this->_providers[] = $provider;
		return $this;
	}

	/**
	 * @throws TConfigurationException when a provider does not implement `IPersonalDataProvider`, or two share a name
	 * @return array<string, IPersonalDataProvider> the providers by name: the configured ones, then the discovered modules
	 */
	public function getProviders(): array
	{
		$candidates = [];
		foreach ($this->_providers as $index => $provider) {
			$candidates[] = $this->_providers[$index] = $this->resolveConfiguredComponent($provider, IPersonalDataProvider::class);
		}
		if ($this->getAutoDiscover()) {
			foreach ($this->getApplication()->getModules() as $module) {
				if ($module instanceof IPersonalDataProvider) {
					$candidates[] = $module;
				}
			}
		}
		$providers = [];
		foreach ($candidates as $provider) {
			$name = $provider->getPersonalDataName();
			if (isset($providers[$name]) && $providers[$name] !== $provider) {
				throw new TConfigurationException('privacy_provider_duplicate', $name);
			}
			$providers[$name] = $provider;
		}
		return $providers;
	}

	// --- requests ----------------------------------------------------------------------------

	/**
	 * Exports the subject's data from every provider.
	 * @param TDataSubject $subject the verified subject
	 * @param ?string $requestedBy who made the request: an operator's name, or `self`
	 * @return array{subject: array, generated: string, request: int, data: array<string, array>} the export
	 */
	public function export(TDataSubject $subject, ?string $requestedBy = null): array
	{
		$data = [];
		$id = $this->runRequest(self::EXPORT, $subject, $requestedBy, function (IPersonalDataProvider $provider, string $name) use ($subject, &$data) {
			$data[$name] = $provider->exportPersonalData($subject);
			return ['items' => \count($data[$name])];
		});
		return [
			'subject' => ['name' => $subject->getName(), 'email' => $subject->getEmail(), 'identifiers' => $subject->getIdentifiers()],
			'generated' => \gmdate('Y-m-d\TH:i:s\Z', $this->getClock()->time()),
			'request' => $id,
			'data' => $data,
		];
	}

	/**
	 * Exports the subject's data as JSON, the machine-readable format portability asks for (GDPR Art. 20).
	 * @param TDataSubject $subject the verified subject
	 * @param ?string $requestedBy who made the request
	 * @return string the export, pretty-printed JSON
	 */
	public function exportJson(TDataSubject $subject, ?string $requestedBy = null): string
	{
		return \json_encode($this->export($subject, $requestedBy), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
	}

	/**
	 * Erases the subject's data from every provider.
	 * @param TDataSubject $subject the verified subject
	 * @param ?string $requestedBy who made the request
	 * @return array<string, TErasureResult> the result per provider; a provider that threw has a result with the error
	 */
	public function erase(TDataSubject $subject, ?string $requestedBy = null): array
	{
		$results = [];
		$this->runRequest(self::ERASE, $subject, $requestedBy, function (IPersonalDataProvider $provider, string $name) use ($subject, &$results) {
			$results[$name] = $provider->erasePersonalData($subject);
			return $results[$name]->toArray();
		}, function (string $name, \Throwable $e) use (&$results) {
			$results[$name] = (new TErasureResult())->addError($e::class);
		});
		return $results;
	}

	/**
	 * Corrects the subject's data in the providers named in `$changes`.
	 * @param TDataSubject $subject the verified subject
	 * @param array<string, array> $changes provider name => field => corrected value
	 * @param ?string $requestedBy who made the request
	 * @throws TInvalidDataValueException when a provider name is unknown
	 * @return array<string, bool> per provider, whether it applied the changes
	 */
	public function rectify(TDataSubject $subject, array $changes, ?string $requestedBy = null): array
	{
		$providers = $this->getProviders();
		foreach (\array_keys($changes) as $name) {
			if (!isset($providers[$name])) {
				throw new TInvalidDataValueException('privacy_provider_unknown', $name);
			}
		}
		$applied = [];
		$this->runRequest(self::RECTIFY, $subject, $requestedBy, function (IPersonalDataProvider $provider, string $name) use ($subject, $changes, &$applied) {
			$applied[$name] = $provider->rectifyPersonalData($subject, $changes[$name]);
			return ['fields' => \array_keys($changes[$name]), 'applied' => $applied[$name]];
		}, null, \array_intersect_key($providers, $changes));
		return $applied;
	}

	/**
	 * Runs a request across providers, records it, and raises the request events.
	 * @param string $type the request type
	 * @param TDataSubject $subject the subject
	 * @param ?string $requestedBy who made the request
	 * @param callable $run runs one provider: ($provider, $name) => outcome array
	 * @param ?callable $failed notes a provider that threw: ($name, $exception)
	 * @param ?array<string, IPersonalDataProvider> $providers the providers; null for all
	 * @return int the request id; 0 when requests are not logged
	 */
	protected function runRequest(string $type, TDataSubject $subject, ?string $requestedBy, callable $run, ?callable $failed = null, ?array $providers = null): int
	{
		$providers ??= $this->getProviders();
		$id = $this->openRequest($type, $subject, $requestedBy);
		$this->onPrivacyRequest(new TPrivacyRequestEventParameter($type, $subject, $id));
		$outcome = [];
		$errors = 0;
		foreach ($providers as $name => $provider) {
			try {
				$outcome[$name] = $run($provider, $name);
			} catch (\Throwable $e) {
				$errors++;
				// The message may hold personal data: the log gets it, the request record only the class.
				Prado::log('The ' . $type . ' request ' . $id . ' failed in provider "' . $name . '": ' . $e->getMessage(), TLogger::ERROR, static::class);
				$outcome[$name] = ['error' => $e::class];
				if ($failed !== null) {
					$failed($name, $e);
				}
			}
		}
		$status = $errors === 0 ? self::STATUS_COMPLETED : ($errors === \count($providers) ? self::STATUS_FAILED : self::STATUS_PARTIAL);
		if ($this->getLogRequests()) {
			$this->getRequestLog()->complete($id, $status, $outcome, $this->getClock()->time());
		}
		$this->onPrivacyRequestComplete(new TPrivacyRequestEventParameter($type, $subject, $id, $status, $outcome));
		return $id;
	}

	/**
	 * Records a request as pending: one the application runs now, or one received outside it and answered later.
	 * @param string $type `export`, `erase` or `rectify`
	 * @param TDataSubject $subject the subject
	 * @param ?string $requestedBy who made the request
	 * @throws TInvalidDataValueException when the type is unknown
	 * @return int the request id; 0 when requests are not logged
	 */
	public function openRequest(string $type, TDataSubject $subject, ?string $requestedBy = null): int
	{
		if (!\in_array($type, [self::EXPORT, self::ERASE, self::RECTIFY], true)) {
			throw new TInvalidDataValueException('privacy_request_type_invalid', $type);
		}
		if (!$this->getLogRequests()) {
			return 0;
		}
		$now = $this->getClock()->time();
		return $this->getRequestLog()->open($type, $subject->getHash(), $requestedBy, $now, $now + $this->getResponseDays() * 86400);
	}

	/**
	 * @param TDataSubject $subject the subject
	 * @return array<int, array> the subject's recorded requests, oldest first
	 */
	public function getRequests(TDataSubject $subject): array
	{
		return $this->getRequestLog()->findBySubject($subject->getHash());
	}

	/**
	 * @return array<int, array> the pending requests whose due date has passed
	 */
	public function getOverdueRequests(): array
	{
		$now = $this->getClock()->time();
		return \array_values(\array_filter($this->getRequestLog()->findPending(), fn ($entry) => $entry['due_at'] < $now));
	}

	/**
	 * Raised before the providers run a request.
	 * @param TPrivacyRequestEventParameter $param the request
	 * @return array the handlers' results
	 */
	public function onPrivacyRequest($param)
	{
		return $this->raiseEvent('onPrivacyRequest', $this, $param);
	}

	/**
	 * Raised after a request ran, with its status and outcome.
	 * @param TPrivacyRequestEventParameter $param the request and its outcome
	 * @return array the handlers' results
	 */
	public function onPrivacyRequestComplete($param)
	{
		return $this->raiseEvent('onPrivacyRequestComplete', $this, $param);
	}

	// --- properties --------------------------------------------------------------------------

	/**
	 * @return bool whether loaded modules implementing `IPersonalDataProvider` join the configured providers
	 */
	public function getAutoDiscover(): bool
	{
		return $this->_autoDiscover;
	}

	/**
	 * @param mixed $value whether loaded modules implementing `IPersonalDataProvider` join the configured providers
	 * @return static the module
	 */
	public function setAutoDiscover($value): static
	{
		$this->_autoDiscover = TPropertyValue::ensureBoolean($value);
		return $this;
	}

	/**
	 * @throws TConfigurationException when the configured log does not implement `IPrivacyRequestLog`
	 * @return IPrivacyRequestLog the request log; a `TDbPrivacyRequestLog` when none is configured
	 */
	public function getRequestLog(): IPrivacyRequestLog
	{
		if (!($this->_requestLog instanceof IPrivacyRequestLog)) {
			$this->_requestLog = $this->_requestLog === null ? new TDbPrivacyRequestLog()
				: $this->resolveConfiguredComponent($this->_requestLog, IPrivacyRequestLog::class);
		}
		return $this->_requestLog;
	}

	/**
	 * @param mixed $value a log, a configuration array (`class` and properties), or a module id; null restores the default
	 * @return static the module
	 */
	public function setRequestLog($value): static
	{
		$this->_requestLog = ($value === '' ? null : $value);
		return $this;
	}

	/**
	 * @return bool whether requests are recorded
	 */
	public function getLogRequests(): bool
	{
		return $this->_logRequests;
	}

	/**
	 * @param mixed $value whether requests are recorded
	 * @return static the module
	 */
	public function setLogRequests($value): static
	{
		$this->_logRequests = TPropertyValue::ensureBoolean($value);
		return $this;
	}

	/**
	 * @return int days to answer a request
	 */
	public function getResponseDays(): int
	{
		return $this->_responseDays;
	}

	/**
	 * @param mixed $value days to answer a request, at least 1
	 * @throws TInvalidDataValueException when the value is below 1
	 * @return static the module
	 */
	public function setResponseDays($value): static
	{
		$value = TPropertyValue::ensureInteger($value);
		if ($value < 1) {
			throw new TInvalidDataValueException('privacy_response_days_invalid', $value);
		}
		$this->_responseDays = $value;
		return $this;
	}
}
