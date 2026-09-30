<?php

/**
 * TProcessingRegistry class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Retention;

use belisoful\Privacy\Rights\TPrivacyShellAction;
use Prado\Exceptions\TConfigurationException;
use Prado\TModule;
use Prado\TPropertyValue;
use Prado\Util\Clock\TApplicationClockAwareTrait;
use Prado\Xml\TXmlElement;

/**
 * TProcessingRegistry class.
 *
 * TProcessingRegistry assembles the records of processing activities (GDPR Art. 30): the
 * controller's details and every `TProcessingActivity` the application declares.
 *
 * ```xml
 * <module id="ropa" class="belisoful\Privacy\Retention\TProcessingRegistry"
 *     Controller="Example Ltd, 1 Main St" Contact="privacy@example.com" DpoContact="dpo@example.com">
 *   <activity Name="accounts" Purpose="Running user accounts" LegalBasis="contract"
 *       SubjectCategories="customers" DataCategories="name, email address, password hash" RetentionPeriod="until the account is closed" />
 * </module>
 * ```
 *
 * Activities come from, in order:
 *
 * 1. `<activity>` elements and {@see addActivity()};
 * 2. with `AutoDiscover` (the default), every loaded module implementing `IProcessingActivityProvider`
 *    (this package's `TConsentModule`, `TPrivacyModule` and `TIpRegionResolver` do);
 * 3. the global event `fxGetProcessingActivities`: any listening component's method of that name
 *    returns an activity or a list of them.
 *
 * An activity's `RetentionPolicy` is resolved against the first `TRetentionModule`, so the record
 * states the period the purge enforces. {@see toArray()}, {@see toJson()} and {@see toMarkdown()}
 * give the record; `prado-cli privacy/ropa` prints it.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TProcessingRegistry extends TModule
{
	use TApplicationClockAwareTrait;

	/** @var TProcessingActivity[] the configured activities */
	private array $_activities = [];

	/** @var bool whether modules implementing IProcessingActivityProvider are discovered */
	private bool $_autoDiscover = true;

	/** @var string the controller */
	private string $_controller = '';

	/** @var string the controller's privacy contact */
	private string $_contact = '';

	/** @var string the data protection officer's contact */
	private string $_dpoContact = '';

	/**
	 * Reads `<activity>` elements (or the `activities` key of a PHP configuration).
	 * @param null|array|TXmlElement $config the module configuration
	 */
	public function init($config)
	{
		if ($config instanceof TXmlElement) {
			foreach ($config->getElementsByTagName('activity') as $element) {
				$this->addActivity($element->getAttributes()->toArray());
			}
		} elseif (\is_array($config)) {
			foreach ($config['activities'] ?? [] as $activity) {
				$this->addActivity($activity);
			}
		}
		TPrivacyShellAction::register($this);
		parent::init($config);
	}

	/**
	 * Adds an activity.
	 * @param array|TProcessingActivity $activity the activity, or its properties
	 * @return static the registry
	 */
	public function addActivity(array|TProcessingActivity $activity): static
	{
		$this->_activities[] = \is_array($activity) ? new TProcessingActivity($activity) : $activity;
		return $this;
	}

	/**
	 * @throws TConfigurationException when an activity has no name, or two different activities share one
	 * @return array<string, TProcessingActivity> the activities by name
	 */
	public function getActivities(): array
	{
		$candidates = $this->_activities;
		if ($this->getAutoDiscover()) {
			foreach ($this->getApplication()->getModules() as $module) {
				if ($module instanceof IProcessingActivityProvider) {
					\array_push($candidates, ...$module->getProcessingActivities());
				}
			}
		}
		foreach ($this->raiseEvent('fxGetProcessingActivities', $this, null) as $result) {
			\array_push($candidates, ...(\is_array($result) ? $result : [$result]));
		}
		$activities = [];
		foreach ($candidates as $activity) {
			$name = $activity->getName();
			if ($name === '') {
				throw new TConfigurationException('privacy_activity_name_missing');
			}
			if (isset($activities[$name]) && $activities[$name]->toArray() !== $activity->toArray()) {
				throw new TConfigurationException('privacy_activity_duplicate', $name);
			}
			$activities[$name] = $activity;
		}
		return $activities;
	}

	/**
	 * @return array{controller: string, contact: string, dpo: string, generated: string, activities: array} the record, each activity with its resolved retention
	 */
	public function toArray(): array
	{
		$retention = null;
		foreach ($this->getApplication()->getModulesByType(TRetentionModule::class) as $retention) {
			break;
		}
		$activities = [];
		foreach ($this->getActivities() as $name => $activity) {
			$entry = $activity->toArray();
			$policy = $entry['RetentionPolicy'] === '' ? null : $retention?->getPolicy($entry['RetentionPolicy']);
			$entry['Retention'] = $policy === null ? $entry['RetentionPeriod'] : \trim($policy->getPeriod() . ' ' . $policy->getDescription());
			$activities[$name] = $entry;
		}
		return [
			'controller' => $this->getController(),
			'contact' => $this->getContact(),
			'dpo' => $this->getDpoContact(),
			'generated' => \gmdate('Y-m-d\TH:i:s\Z', $this->getClock()->time()),
			'activities' => $activities,
		];
	}

	/**
	 * @return string the record as pretty-printed JSON
	 */
	public function toJson(): string
	{
		return \json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
	}

	/**
	 * @return string the record as a Markdown document: the controller, then a table of the activities
	 */
	public function toMarkdown(): string
	{
		$record = $this->toArray();
		$cell = fn ($value) => \str_replace(['|', "\n"], ['\|', ' '], \is_array($value) ? \implode(', ', $value) : (string) $value) ?: '-';
		$lines = [
			'# Records of processing activities',
			'',
			'- Controller: ' . $cell($record['controller']),
			'- Contact: ' . $cell($record['contact']),
			'- Data protection officer: ' . $cell($record['dpo']),
			'- Generated: ' . $record['generated'],
			'',
			'| Activity | Purpose | Legal basis | Data subjects | Personal data | Recipients | Transfers | Retention | Security |',
			'|---|---|---|---|---|---|---|---|---|',
		];
		foreach ($record['activities'] as $name => $entry) {
			$basis = $entry['LegalBasis'] === '' ? '' : $entry['LegalBasis'] . ' (' . TLegalBasis::ARTICLE[$entry['LegalBasis']] . ')' . ($entry['LegalBasisNote'] === '' ? '' : ': ' . $entry['LegalBasisNote']);
			$lines[] = '| ' . \implode(' | ', \array_map($cell, [$name, $entry['Purpose'], $basis, $entry['SubjectCategories'], $entry['DataCategories'], $entry['Recipients'], $entry['Transfers'], $entry['Retention'], $entry['SecurityMeasures']])) . ' |';
		}
		return \implode("\n", $lines) . "\n";
	}

	/**
	 * @return bool whether modules implementing `IProcessingActivityProvider` are discovered
	 */
	public function getAutoDiscover(): bool
	{
		return $this->_autoDiscover;
	}

	/**
	 * @param mixed $value whether modules implementing `IProcessingActivityProvider` are discovered
	 * @return static the registry
	 */
	public function setAutoDiscover($value): static
	{
		$this->_autoDiscover = TPropertyValue::ensureBoolean($value);
		return $this;
	}

	/**
	 * @return string the controller's name and address
	 */
	public function getController(): string
	{
		return $this->_controller;
	}

	/**
	 * @param mixed $value the controller's name and address
	 * @return static the registry
	 */
	public function setController($value): static
	{
		$this->_controller = \trim(TPropertyValue::ensureString($value));
		return $this;
	}

	/**
	 * @return string the controller's privacy contact
	 */
	public function getContact(): string
	{
		return $this->_contact;
	}

	/**
	 * @param mixed $value the controller's privacy contact
	 * @return static the registry
	 */
	public function setContact($value): static
	{
		$this->_contact = \trim(TPropertyValue::ensureString($value));
		return $this;
	}

	/**
	 * @return string the data protection officer's contact
	 */
	public function getDpoContact(): string
	{
		return $this->_dpoContact;
	}

	/**
	 * @param mixed $value the data protection officer's contact
	 * @return static the registry
	 */
	public function setDpoContact($value): static
	{
		$this->_dpoContact = \trim(TPropertyValue::ensureString($value));
		return $this;
	}
}
