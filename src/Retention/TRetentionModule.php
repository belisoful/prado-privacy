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
use Prado\Exceptions\TInvalidDataValueException;
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
 * the site's counsel advises. A policy that fails is logged and reported as -1; the others still
 * run. `onPurged` carries the counts. The module advertises its task to the cron shell through
 * `fxGetCronTaskInfos`.
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
	 * Purges every policy's data; the cron task.
	 * @return array<string, int> policy => items removed; -1 when the policy failed
	 */
	public function purge(): array
	{
		$now = $this->getClock()->time();
		$counts = [];
		foreach ($this->getPolicies() as $name => $policy) {
			try {
				$counts[$name] = $policy->getPurger()->purge($policy->getCutoff($now));
				Prado::log('Retention policy "' . $name . '" removed ' . $counts[$name] . ' items.', TLogger::INFO, static::class);
			} catch (\Throwable $e) {
				$counts[$name] = -1;
				Prado::log('Retention policy "' . $name . '" failed: ' . $e->getMessage(), TLogger::ERROR, static::class);
			}
		}
		$this->onPurged(new TEventParameter($counts));
		return $counts;
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
	 * Lists the purge task for the cron shell (`prado-cli cron/tasks`).
	 * @param object $sender the object raising fxGetCronTaskInfos
	 * @param mixed $param the parameter
	 * @return TCronTaskInfo the purge task
	 */
	public function fxGetCronTaskInfos($sender, $param)
	{
		return new TCronTaskInfo('privacyretention', $this->getID() . '->purge', $this, Prado::localize('Privacy retention purge'), Prado::localize('Removes personal data past its retention period.'));
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
