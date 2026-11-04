<?php

/**
 * TConsentPersonalDataProvider class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Rights;

use belisoful\Privacy\Consent\TConsentModule;
use belisoful\Privacy\Consent\TDbConsentStore;
use Prado\Exceptions\TConfigurationException;
use Prado\TModule;
use Prado\TPropertyValue;

/**
 * TConsentPersonalDataProvider class.
 *
 * TConsentPersonalDataProvider answers for this package's own consent data:
 *
 * | Request | Result |
 * |---|---|
 * | export | the current choice (for the logged-in subject), the choices a `TDbConsentStore` holds for the subject, and the subject's consent log entries |
 * | erase | the current choice is withdrawn (for the logged-in subject) and the subject's `TDbConsentStore` rows are deleted; the log entries are retained as proof of consent |
 *
 * Outside an opt-in region a withdrawal stores the visitor's opt-out ({@see TConsentModule::withdraw()}),
 * so erasure keeps that one record, reported as retained, rather than letting the opt-out lapse.
 *
 * The consent log holds hashes only, and it is the controller's proof that consent was given (GDPR
 * Art. 7(1)); erasure does not reach it (Art. 17(3)(b) and (e)). The retention policy on the log
 * removes the entries.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 */
class TConsentPersonalDataProvider extends TModule implements IPersonalDataProvider
{
	/** The reason consent log entries are retained. */
	public const RETAINED_REASON = 'consent log: proof of consent (GDPR Art. 7(1), 17(3)(b)); hashed, removed by retention';

	/** The reason a stored opt-out is retained. */
	public const OPT_OUT_REASON = 'opt-out: kept so it continues to be honored (CCPA regulations §7026); expires with the choice';

	/** @var string the id of the consent module */
	private string $_consentModuleID = '';

	/**
	 * @return string `consent`
	 */
	public function getPersonalDataName(): string
	{
		return 'consent';
	}

	/**
	 * @param TDataSubject $subject the verified subject
	 * @return array{current: ?array, stored: array, log: array} the subject's consent data
	 */
	public function exportPersonalData(TDataSubject $subject): array
	{
		$module = $this->getConsentModule();
		$store = $module->getStore();
		$current = $subject->getIsCurrentUser() ? $module->getStoredRecord()?->toArray() : null;
		$stored = $store instanceof TDbConsentStore ? \array_map(fn ($record) => $record->toArray(), $store->findBySubject($subject->getHash())) : [];
		$log = $module->getLogEnabled() ? $module->getLog()->findBySubject($subject->getHash()) : [];
		return ['current' => $current, 'stored' => $stored, 'log' => $log];
	}

	/**
	 * Withdraws the current choice for the logged-in subject and deletes the subject's stored choices; retains the log and a stored opt-out.
	 * @param TDataSubject $subject the verified subject
	 * @return TErasureResult the choices erased and the log entries retained
	 */
	public function erasePersonalData(TDataSubject $subject): TErasureResult
	{
		$module = $this->getConsentModule();
		$result = new TErasureResult();
		$optOut = null;
		if ($subject->getIsCurrentUser() && $module->getStoredRecord() !== null) {
			$module->withdraw();
			$optOut = $module->getStoredRecord();
			if ($optOut === null) {
				$result->addErased(1);
			} else {
				$result->addRetained(1, self::OPT_OUT_REASON);
			}
		}
		$store = $module->getStore();
		if ($store instanceof TDbConsentStore) {
			$result->addErased($store->deleteBySubject($subject->getHash(), $optOut?->getId()));
		}
		if ($module->getLogEnabled() && ($count = \count($module->getLog()->findBySubject($subject->getHash()))) > 0) {
			$result->addRetained($count, self::RETAINED_REASON);
		}
		return $result;
	}

	/**
	 * @param TDataSubject $subject the verified subject
	 * @param array<string, mixed> $changes field => corrected value
	 * @return bool false: a visitor changes a choice in the consent preferences, and the log is not rewritten
	 */
	public function rectifyPersonalData(TDataSubject $subject, array $changes): bool
	{
		return false;
	}

	/**
	 * @throws TConfigurationException when the application has no such consent module
	 * @return TConsentModule the consent module
	 */
	public function getConsentModule(): TConsentModule
	{
		$module = TConsentModule::findModule($this->_consentModuleID);
		if ($module === null) {
			throw new TConfigurationException('privacy_consent_module_missing', static::class, $this->_consentModuleID);
		}
		return $module;
	}

	/**
	 * @return string the id of the consent module; empty for the first one
	 */
	public function getConsentModuleID(): string
	{
		return $this->_consentModuleID;
	}

	/**
	 * @param mixed $value the id of the consent module; empty for the first one
	 * @return static the provider
	 */
	public function setConsentModuleID($value): static
	{
		$this->_consentModuleID = \trim(TPropertyValue::ensureString($value));
		return $this;
	}
}
