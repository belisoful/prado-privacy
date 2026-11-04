<?php

/**
 * TRetentionModule class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Retention;

use belisoful\Privacy\Consent\TConsentModule;
use belisoful\Privacy\Consent\TDbConsentStore;
use belisoful\Privacy\Rights\TPrivacyModule;
use belisoful\Privacy\Rights\TPrivacyShellAction;
use Prado\Exceptions\TCancelledException;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\Exceptions\TInvalidOperationException;
use Prado\IO\Async\TAsync;
use Prado\IO\Async\TCancellationToken;
use Prado\Prado;
use Prado\TEventParameter;
use Prado\TModule;
use Prado\TPropertyValue;
use Prado\Util\Clock\TApplicationClockAwareTrait;
use Prado\Util\Cron\TCronTaskInfo;
use Prado\Util\Log\TLogger;
use Prado\Xml\TXmlElement;

/**
 * TRetentionModule class.
 *
 * TRetentionModule holds the retention policies (GDPR Art. 5(1)(e)) and purges the data past them.
 * `TCronModule` runs the purge:
 *
 * ```xml
 * <module id="retention" class="belisoful\Privacy\Retention\TRetentionModule">
 *   <policy Name="orders" Period="P10Y" Description="Invoices: commercial and tax law">
 *     <purger class="belisoful\Privacy\Retention\TDbRetentionPurger" ConnectionID="db" Table="orders" TimeColumn="created_at" />
 *   </policy>
 * </module>
 * <module id="cron" class="Prado\Util\Cron\TCronModule">
 *   <job Name="privacy-retention" Schedule="17 3 * * *" Task="retention->purge" />
 * </module>
 * ```
 *
 * With `IncludeBuiltIn` (the default) the module adds a policy for this package's own data, unless
 * a configured policy takes the name:
 *
 * | Policy | Data | Period |
 * |---|---|---|
 * | `consent-log` | the consent log, when the first `TConsentModule` logs | `ConsentLogPeriod` (`P3Y`) |
 * | `consent-state` | expired rows of a `TDbConsentStore` | `P0D`: removed once expired |
 * | `privacy-requests` | the request log of the first `TPrivacyModule` | `RequestLogPeriod` (`P3Y`) |
 *
 * Three years matches the limitation period for claims in many EU member states; set the periods
 * the site's counsel advises.
 *
 * {@see purge()} runs every policy. A policy that fails is logged and counted as -1, and the
 * others still run. `onPurged` carries the counts. When a policy failed, `purge()` then throws, so
 * `TCronModule` records the task as failed. {@see purgePolicies()} returns the counts without
 * throwing.
 *
 * `Task="retention->purge"` runs without a deadline. {@see TRetentionCronTask} passes the cron
 * run's cancellation token, so `TaskTimeout` (or the job's `Timeout`) stops the purge between
 * policies:
 *
 * ```xml
 * <module id="cron" class="Prado\Util\Cron\TCronModule" TaskTimeout="600">
 *   <job Name="privacy-retention" Schedule="17 3 * * *" Task="belisoful\Privacy\Retention\TRetentionCronTask" ModuleId="retention" />
 * </module>
 * ```
 *
 * The module advertises {@see TRetentionCronTask} to the cron shell through `fxGetCronTaskInfos`.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 */
class TRetentionModule extends TModule
{
	use TApplicationClockAwareTrait;

	/** The name of the consent log policy. */
	public const CONSENT_LOG = 'consent-log';

	/** The name of the expired consent state policy. */
	public const CONSENT_STATE = 'consent-state';

	/** The name of the request log policy. */
	public const PRIVACY_REQUESTS = 'privacy-requests';

	/** The pause before each policy of a purge with a token, in seconds. */
	public const YIELD_SECONDS = 0.000001;

	/** @var array<string, TRetentionPolicy> the configured policies by name */
	private array $_policies = [];

	/** @var bool whether the package's own policies are added */
	private bool $_includeBuiltIn = true;

	/** @var string the consent log period */
	private string $_consentLogPeriod = 'P3Y';

	/** @var string the request log period */
	private string $_requestLogPeriod = 'P3Y';

	/**
	 * Reads `<policy>` elements, each with an optional `<purger>` element (or the `policies` key of
	 * a PHP configuration: arrays of properties with a `purger` key).
	 * @param null|array|TXmlElement $config the module configuration
	 * @throws TInvalidDataValueException when a policy is not valid
	 */
	public function init($config)
	{
		if ($config instanceof TXmlElement) {
			foreach ($config->getElementsByTagName('policy') as $element) {
				$properties = $element->getAttributes()->toArray();
				if (($purger = $element->getElementByTagName('purger')) !== null) {
					$properties['Purger'] = $purger->getAttributes()->toArray();
				}
				$this->addPolicy($properties);
			}
		} elseif (\is_array($config)) {
			foreach ($config['policies'] ?? [] as $policy) {
				$this->addPolicy($policy);
			}
		}
		TPrivacyShellAction::register($this);
		parent::init($config);
	}

	/**
	 * Adds a policy, replacing the one with the same name.
	 * @param array|TRetentionPolicy $policy the policy, or its properties (`Name`, `Period`, `Purger`, `Description`)
	 * @throws TInvalidDataValueException when the policy has no name or an invalid period
	 * @return static the module
	 */
	public function addPolicy(array|TRetentionPolicy $policy): static
	{
		if (\is_array($policy)) {
			$properties = $policy;
			$policy = new TRetentionPolicy();
			foreach ($properties as $property => $value) {
				$policy->setSubProperty($property, $value);
			}
		}
		if ($policy->getName() === '') {
			throw new TInvalidDataValueException('privacy_retention_name_missing');
		}
		$this->_policies[$policy->getName()] = $policy;
		return $this;
	}

	/**
	 * @return array<string, TRetentionPolicy> the policies by name: the configured ones, then the built-in ones not overridden
	 */
	public function getPolicies(): array
	{
		return $this->_policies + ($this->getIncludeBuiltIn() ? $this->getBuiltInPolicies() : []);
	}

	/**
	 * @param string $name a policy name
	 * @return ?TRetentionPolicy the policy; null when there is none
	 */
	public function getPolicy(string $name): ?TRetentionPolicy
	{
		return $this->getPolicies()[$name] ?? null;
	}

	/**
	 * @return array<string, TRetentionPolicy> the policies for this package's own data
	 */
	public function getBuiltInPolicies(): array
	{
		$app = $this->getApplication();
		$policies = [];
		foreach ($app->getModulesByType(TConsentModule::class) as $consent) {
			if ($consent->getLogEnabled()) {
				$policies[self::CONSENT_LOG] = new TRetentionPolicy(self::CONSENT_LOG, $this->getConsentLogPeriod(), $consent->getLog(), 'Consent log: proof of consent (GDPR Art. 7(1)) for the limitation period of claims.');
			}
			if (($store = $consent->getStore()) instanceof TDbConsentStore) {
				$policies[self::CONSENT_STATE] = new TRetentionPolicy(self::CONSENT_STATE, 'P0D', $store, 'Stored consent choices, once expired.');
			}
			break;
		}
		foreach ($app->getModulesByType(TPrivacyModule::class) as $rights) {
			if ($rights->getLogRequests()) {
				$policies[self::PRIVACY_REQUESTS] = new TRetentionPolicy(self::PRIVACY_REQUESTS, $this->getRequestLogPeriod(), $rights->getRequestLog(), 'Data subject requests: proof that they were answered (GDPR Art. 5(2), 12).');
			}
			break;
		}
		return $policies;
	}

	/**
	 * Purges every policy's data, and throws when a policy failed.
	 * @param ?TCancellationToken $token a token checked before each policy; null for no deadline
	 * @throws TCancelledException when the token is cancelled; the remaining policies do not run
	 * @throws TInvalidOperationException when a policy failed, after every policy ran and `onPurged` was raised
	 * @return array<string, int> policy => items removed
	 */
	public function purge(?TCancellationToken $token = null): array
	{
		$counts = $this->purgePolicies($token);
		$failed = \array_keys($counts, -1, true);
		if ($failed !== []) {
			throw new TInvalidOperationException('privacy_retention_purge_failed', \implode(', ', $failed));
		}
		return $counts;
	}

	/**
	 * Purges every policy's data and raises `onPurged` with the counts. A failed policy is logged
	 * and counted as -1. With a token, the purge yields to the event loop before each policy, so
	 * the token's deadline and other fibers can run, then checks the token.
	 * @param ?TCancellationToken $token a token checked before each policy; null for no deadline
	 * @throws TCancelledException when the token is cancelled, or a purger was cancelled
	 * @return array<string, int> policy => items removed; -1 when the policy failed
	 */
	public function purgePolicies(?TCancellationToken $token = null): array
	{
		$now = $this->getClock()->time();
		$counts = [];
		foreach ($this->getPolicies() as $name => $policy) {
			if ($token !== null) {
				$this->yieldToLoop();
				$token->throwIfCancelled();
			}
			try {
				$counts[$name] = $policy->getPurger()->purge($policy->getCutoff($now));
				Prado::log('Retention policy "' . $name . '" removed ' . $counts[$name] . ' items.', TLogger::INFO, static::class);
			} catch (TCancelledException $e) {
				Prado::log('Retention purge cancelled at policy "' . $name . '".', TLogger::WARNING, static::class);
				throw $e;
			} catch (\Throwable $e) {
				$counts[$name] = -1;
				Prado::log('Retention policy "' . $name . '" failed: ' . $e->getMessage(), TLogger::ERROR, static::class);
			}
		}
		$this->onPurged(new TEventParameter($counts));
		return $counts;
	}

	/**
	 * Lets the event loop run its due callbacks: a token's deadline and other cron fibers.
	 */
	protected function yieldToLoop(): void
	{
		TAsync::sleep(self::YIELD_SECONDS);
	}

	/**
	 * Raised after a purge, with the counts per policy.
	 * @param TEventParameter $param policy => items removed
	 * @return array the handlers' results
	 */
	public function onPurged($param)
	{
		return $this->raiseEvent('onPurged', $this, $param);
	}

	/**
	 * Lists the purge task, {@see TRetentionCronTask}, for the cron shell (`prado-cli cron/tasks`).
	 * @param object $sender the object raising fxGetCronTaskInfos
	 * @param mixed $param the parameter
	 * @return TCronTaskInfo the purge task
	 */
	public function fxGetCronTaskInfos($sender, $param)
	{
		return new TCronTaskInfo('privacyretention', TRetentionCronTask::class, $this->getID(), Prado::localize('Privacy retention purge'), Prado::localize('Removes personal data past its retention period.'));
	}

	/**
	 * @return bool whether the package's own policies are added
	 */
	public function getIncludeBuiltIn(): bool
	{
		return $this->_includeBuiltIn;
	}

	/**
	 * @param mixed $value whether the package's own policies are added
	 * @return static the module
	 */
	public function setIncludeBuiltIn($value): static
	{
		$this->_includeBuiltIn = TPropertyValue::ensureBoolean($value);
		return $this;
	}

	/**
	 * @return string the consent log period, an ISO 8601 duration
	 */
	public function getConsentLogPeriod(): string
	{
		return $this->_consentLogPeriod;
	}

	/**
	 * @param mixed $value the consent log period, an ISO 8601 duration
	 * @throws TInvalidDataValueException when the value is not an ISO 8601 duration
	 * @return static the module
	 */
	public function setConsentLogPeriod($value): static
	{
		$this->_consentLogPeriod = (new TRetentionPolicy())->setPeriod($value)->getPeriod();
		return $this;
	}

	/**
	 * @return string the request log period, an ISO 8601 duration
	 */
	public function getRequestLogPeriod(): string
	{
		return $this->_requestLogPeriod;
	}

	/**
	 * @param mixed $value the request log period, an ISO 8601 duration
	 * @throws TInvalidDataValueException when the value is not an ISO 8601 duration
	 * @return static the module
	 */
	public function setRequestLogPeriod($value): static
	{
		$this->_requestLogPeriod = (new TRetentionPolicy())->setPeriod($value)->getPeriod();
		return $this;
	}
}
