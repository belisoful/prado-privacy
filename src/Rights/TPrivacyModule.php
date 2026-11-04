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
use Prado\TApplicationMode;
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
 * <module id="privacy-rights" class="belisoful\Privacy\Rights\TPrivacyModule" ResponsePeriod="P1M">
 *   <provider class="belisoful\Privacy\Rights\TUserPersonalDataProvider" />
 *   <provider class="belisoful\Privacy\Rights\TConsentPersonalDataProvider" />
 * </module>
 * ```
 *
 * ```php
 * // After the application verified the requester's identity:
 * $json = $privacy->exportJson(new TDataSubject('alice'));
 * $results = $privacy->erase(new TDataSubject('alice'), 'operator:bob');
 * // A request received by post, recorded now and answered later:
 * $id = $privacy->openRequest('erase', new TDataSubject('alice'), 'letter');
 * $results = $privacy->erase(new TDataSubject('alice'), null, $id);
 * ```
 *
 * Providers come from `<provider>` elements, `addProvider()`, and, with `AutoDiscover` (the
 * default), every loaded application module that implements `IPersonalDataProvider`. A provider
 * that throws, reports an erasure error, or does not apply a rectification has failed; it does not
 * stop the others. The request completes as `partial`, or `failed` when every provider failed.
 * The exception class goes to the application log and the request record; the exception message
 * goes to the application log in Debug mode only.
 *
 * Every request is recorded in the request log (`LogRequests`, `RequestLog`, `TDbPrivacyRequestLog`
 * on the runtime sqlite database by default) with its due date: `ResponsePeriod` after the request,
 * an ISO 8601 duration counted in calendar terms in UTC (`P1M` by default: 1 February is due
 * 1 March). GDPR Art. 12(3) gives one month, extendable by two further months; CCPA §1798.130(a)(2)
 * gives 45 days, extendable by 45 more (`ResponsePeriod="P45D"`). {@see extendRequest()} moves a
 * due date and records the reason.
 *
 * A `pending`, `partial` or `failed` request is open. {@see openRequest()} records a request
 * received outside the application (by mail, by phone) so it counts toward the deadline; passing
 * its id to {@see export()}, {@see erase()} or {@see rectify()} answers it, and answers a `partial`
 * or `failed` request again. {@see getOverdueRequests()} lists the open requests past their due date.
 *
 * With a `TShellApplication`, the module registers `prado-cli privacy/export`, `privacy/erase`,
 * `privacy/extend` and `privacy/requests`.
 *
 * The module does not verify identity: the caller verifies that the requester is the subject or
 * acts for them, before building the `TDataSubject`.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
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

	/** @var string the time to answer a request, an ISO 8601 duration */
	private string $_responsePeriod = 'P1M';

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
	 * Exports the subject's data from every provider, with the subject's recorded requests.
	 *
	 * `status` is `completed` when every provider answered, `partial` or `failed` otherwise.
	 * `errors` maps each provider that threw to the exception class; its `data` entry is null.
	 * `requests` is the subject's request history as the log holds it after this request, so it
	 * includes this request; it is empty when requests are not logged.
	 * @param TDataSubject $subject the verified subject
	 * @param ?string $requestedBy who made the request: an operator's name, or `self`
	 * @param ?int $requestId an open export request to answer; null opens a new one
	 * @throws TInvalidDataValueException when `$requestId` is not an open export request of the subject
	 * @return array{subject: array, generated: string, request: int, status: string, errors: array<string, string>, requests: array<int, array>, data: array<string, ?array>} the export
	 */
	public function export(TDataSubject $subject, ?string $requestedBy = null, ?int $requestId = null): array
	{
		$data = $errors = [];
		[$id, $status] = $this->runRequest(self::EXPORT, $subject, $requestedBy, function (IPersonalDataProvider $provider, string $name) use ($subject, &$data) {
			$data[$name] = $provider->exportPersonalData($subject);
			return ['items' => \count($data[$name])];
		}, function (string $name, \Throwable $e) use (&$data, &$errors) {
			$data[$name] = null;
			$errors[$name] = $e::class;
		}, null, $requestId);
		return [
			'subject' => ['name' => $subject->getName(), 'email' => $subject->getEmail(), 'identifiers' => $subject->getIdentifiers()],
			'generated' => \gmdate('Y-m-d\TH:i:s\Z', $this->getClock()->time()),
			'request' => $id,
			'status' => $status,
			'errors' => $errors,
			'requests' => $this->getLogRequests() ? $this->getRequests($subject) : [],
			'data' => $data,
		];
	}

	/**
	 * Exports the subject's data as JSON, the machine-readable format portability asks for (GDPR Art. 20).
	 * Invalid UTF-8 in the data becomes U+FFFD, so encoding cannot fail after the request is recorded.
	 * @param TDataSubject $subject the verified subject
	 * @param ?string $requestedBy who made the request
	 * @param ?int $requestId an open export request to answer; null opens a new one
	 * @throws TInvalidDataValueException when `$requestId` is not an open export request of the subject
	 * @return string the export, pretty-printed JSON
	 */
	public function exportJson(TDataSubject $subject, ?string $requestedBy = null, ?int $requestId = null): string
	{
		return \json_encode($this->export($subject, $requestedBy, $requestId), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
	}

	/**
	 * Erases the subject's data from every provider.
	 * @param TDataSubject $subject the verified subject
	 * @param ?string $requestedBy who made the request
	 * @param ?int $requestId an open erase request to answer; null opens a new one
	 * @throws TInvalidDataValueException when `$requestId` is not an open erase request of the subject
	 * @return array<string, TErasureResult> the result per provider; a provider that threw has a result with the error
	 */
	public function erase(TDataSubject $subject, ?string $requestedBy = null, ?int $requestId = null): array
	{
		$results = [];
		$this->runRequest(self::ERASE, $subject, $requestedBy, function (IPersonalDataProvider $provider, string $name) use ($subject, &$results) {
			$results[$name] = $provider->erasePersonalData($subject);
			return $results[$name]->toArray();
		}, function (string $name, \Throwable $e) use (&$results) {
			$results[$name] = (new TErasureResult())->addError($e::class);
		}, null, $requestId);
		return $results;
	}

	/**
	 * Corrects the subject's data in the providers named in `$changes`.
	 * @param TDataSubject $subject the verified subject
	 * @param array<string, array> $changes provider name => field => corrected value
	 * @param ?string $requestedBy who made the request
	 * @param ?int $requestId an open rectify request to answer; null opens a new one
	 * @throws TInvalidDataValueException when a provider name is unknown, or `$requestId` is not an open rectify request of the subject
	 * @return array<string, bool> per provider, whether it applied the changes; false for a provider that threw
	 */
	public function rectify(TDataSubject $subject, array $changes, ?string $requestedBy = null, ?int $requestId = null): array
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
		}, function (string $name) use (&$applied) {
			$applied[$name] = false;
		}, \array_intersect_key($providers, $changes), $requestId);
		return $applied;
	}

	/**
	 * Runs a request across providers, records it, and raises the request events.
	 *
	 * A provider fails when it throws, when its outcome has `errors`, or when its outcome has
	 * `applied` false. The status is `completed` when no provider failed, `failed` when every
	 * provider failed, and `partial` otherwise. A provider exception is logged at ERROR with its
	 * class and the request id; its message, which may hold personal data, only in Debug mode.
	 * @param string $type the request type
	 * @param TDataSubject $subject the subject
	 * @param ?string $requestedBy who made the request
	 * @param callable $run runs one provider: ($provider, $name) => outcome array
	 * @param ?callable $failed notes a provider that threw: ($name, $exception)
	 * @param ?array<string, IPersonalDataProvider> $providers the providers; null for all
	 * @param ?int $requestId an open request to answer; null opens a new one
	 * @throws TInvalidDataValueException when `$requestId` is not an open request of this type and subject
	 * @return array{0: int, 1: string} the request id (0 when requests are not logged) and the status
	 */
	protected function runRequest(string $type, TDataSubject $subject, ?string $requestedBy, callable $run, ?callable $failed = null, ?array $providers = null, ?int $requestId = null): array
	{
		$providers ??= $this->getProviders();
		$id = $requestId === null ? $this->openRequest($type, $subject, $requestedBy) : $this->requireOpenRequest($requestId, $type, $subject);
		$this->onPrivacyRequest(new TPrivacyRequestEventParameter($type, $subject, $id));
		$outcome = [];
		$errors = 0;
		$debug = $this->getApplication()->getMode() === TApplicationMode::Debug;
		foreach ($providers as $name => $provider) {
			try {
				$outcome[$name] = $run($provider, $name);
				$errors += (int) (($outcome[$name]['errors'] ?? []) !== [] || ($outcome[$name]['applied'] ?? true) === false);
			} catch (\Throwable $e) {
				$errors++;
				Prado::log('The ' . $type . ' request ' . $id . ' failed in provider "' . $name . '": ' . $e::class . ($debug ? ': ' . $e->getMessage() : ''), TLogger::ERROR, static::class);
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
		return [$id, $status];
	}

	/**
	 * @param int $id the request id
	 * @param string $type the request type
	 * @param TDataSubject $subject the subject
	 * @throws TInvalidDataValueException when the request is unknown, closed, of another type or of another subject
	 * @return int the request id; 0 when requests are not logged
	 */
	protected function requireOpenRequest(int $id, string $type, TDataSubject $subject): int
	{
		if (!$this->getLogRequests()) {
			return 0;
		}
		$entry = $this->getRequestLog()->find($id);
		if ($entry === null || !\in_array($entry['status'], IPrivacyRequestLog::OPEN_STATUSES, true) || $entry['type'] !== $type || $entry['subject_hash'] !== $subject->getHash()) {
			throw new TInvalidDataValueException('privacy_request_not_open', $id, $type);
		}
		return $id;
	}

	/**
	 * Records a request as pending: one the application runs now, or one received outside it and
	 * answered later by passing its id to {@see export()}, {@see erase()} or {@see rectify()}.
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
		return $this->getRequestLog()->open($type, $subject->getHash(), $requestedBy, $now, static::addPeriod($now, $this->getResponsePeriod()));
	}

	/**
	 * Moves an open request's due date by a further period and records the reason. GDPR Art. 12(3)
	 * allows two further months, CCPA §1798.130(a)(2) 45 further days. The module does not cap
	 * the total; the application tells the subject of the extension and its reason.
	 * @param int $id the request id
	 * @param string $period the extension, an ISO 8601 duration (`P2M`, `P45D`)
	 * @param string $reason why the answer needs more time, without personal data
	 * @throws TInvalidDataValueException when the period is not a duration above zero, the reason is empty, or the request is not open
	 * @return int the new due date, in Unix seconds
	 */
	public function extendRequest(int $id, string $period, string $reason): int
	{
		$period = static::ensurePeriod($period);
		if (($reason = \trim($reason)) === '') {
			throw new TInvalidDataValueException('privacy_request_reason_missing', $id);
		}
		$entry = $this->getRequestLog()->find($id);
		if ($entry === null || !\in_array($entry['status'], IPrivacyRequestLog::OPEN_STATUSES, true)) {
			throw new TInvalidDataValueException('privacy_request_not_open', $id, $entry['type'] ?? 'unknown');
		}
		$dueAt = static::addPeriod($entry['due_at'], $period);
		$this->getRequestLog()->extend($id, $dueAt, $reason, $this->getClock()->time());
		return $dueAt;
	}

	/**
	 * Request rows link on {@see TDataSubject::getHash()}, the HMAC of the user name: after a
	 * rename, or a change of the application's ValidationKey, the earlier requests are not found.
	 * @param TDataSubject $subject the subject
	 * @return array<int, array> the subject's recorded requests, oldest first
	 */
	public function getRequests(TDataSubject $subject): array
	{
		return $this->getRequestLog()->findBySubject($subject->getHash());
	}

	/**
	 * @return array<int, array> the open requests (`pending`, `partial` or `failed`) whose due date has passed
	 */
	public function getOverdueRequests(): array
	{
		$now = $this->getClock()->time();
		return \array_values(\array_filter($this->getRequestLog()->findOpen(), fn ($entry) => $entry['due_at'] < $now));
	}

	/**
	 * Adds a calendar period to a time, in UTC. Years and months move the date first; a day the
	 * target month lacks becomes its last day (31 January + `P1M` is the end of February), as
	 * Regulation 1182/71 Art. 3(2)(c) counts periods. Days and times are added after.
	 * @param int $time a Unix time
	 * @param string $period an ISO 8601 duration
	 * @throws \Exception when the period is not an ISO 8601 duration
	 * @return int the Unix time after the period
	 */
	public static function addPeriod(int $time, string $period): int
	{
		$date = new \DateTimeImmutable('@' . $time);
		$interval = new \DateInterval($period);
		$month = $date->modify('first day of this month')->add(new \DateInterval('P' . ($interval->y * 12 + $interval->m) . 'M'));
		$month = $month->setDate((int) $month->format('Y'), (int) $month->format('n'), \min((int) $date->format('j'), (int) $month->format('t')));
		$interval->y = $interval->m = 0;
		return $month->add($interval)->getTimestamp();
	}

	/**
	 * @param mixed $value an ISO 8601 duration
	 * @throws TInvalidDataValueException when the value is not a duration above zero
	 * @return string the duration, upper case
	 */
	protected static function ensurePeriod($value): string
	{
		$value = \strtoupper(\trim(TPropertyValue::ensureString($value)));
		try {
			$valid = static::addPeriod(0, $value) > 0;
		} catch (\Exception $e) {
			$valid = false;
		}
		if (!$valid) {
			throw new TInvalidDataValueException('privacy_response_period_invalid', $value);
		}
		return $value;
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
	 * @return string the time to answer a request, an ISO 8601 duration
	 */
	public function getResponsePeriod(): string
	{
		return $this->_responsePeriod;
	}

	/**
	 * @param mixed $value the time to answer a request, an ISO 8601 duration above zero (`P1M`, `P45D`)
	 * @throws TInvalidDataValueException when the value is not a duration above zero
	 * @return static the module
	 */
	public function setResponsePeriod($value): static
	{
		$this->_responsePeriod = static::ensurePeriod($value);
		return $this;
	}
}
