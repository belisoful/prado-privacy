<?php

/**
 * TConsentControlTrait class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Consent\Controls;

use belisoful\Privacy\Consent\TConsentModule;
use Prado\Exceptions\TConfigurationException;
use Prado\Prado;
use Prado\TPropertyValue;
use Prado\Web\UI\ActiveControls\TCallbackEventParameter;

/**
 * TConsentControlTrait trait.
 *
 * TConsentControlTrait gives a consent control its module (`ConsentModuleID`, or the application's
 * first `TConsentModule`), the refresh of every consent control after a callback changed the
 * consent, and the default stylesheet.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
trait TConsentControlTrait
{
	/** @var string the id of the consent module; empty for the first one */
	private string $_consentModuleID = '';

	/** @var bool whether the default stylesheet is registered */
	private bool $_defaultStyle = true;

	/**
	 * @return string the id of the consent module; empty for the application's first `TConsentModule`
	 */
	public function getConsentModuleID(): string
	{
		return $this->_consentModuleID;
	}

	/**
	 * @param mixed $value the id of the consent module; empty for the application's first `TConsentModule`
	 */
	public function setConsentModuleID($value): void
	{
		$this->_consentModuleID = \trim(TPropertyValue::ensureString($value));
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
	 * Re-renders every registered consent control into the callback response; an `OnCallback` handler.
	 * @param mixed $sender the control that raised the callback
	 * @param TCallbackEventParameter $param the callback's parameter
	 */
	public function refreshConsentControls($sender, $param): void
	{
		foreach ($this->getConsentModule()->getConsentControls() as $control) {
			$control->refreshConsent($param->getNewWriter());
		}
	}

	/**
	 * @return bool whether the default stylesheet (`assets/consent.css`) is registered
	 */
	public function getDefaultStyle(): bool
	{
		return $this->_defaultStyle;
	}

	/**
	 * @param mixed $value whether the default stylesheet is registered; turn it off to style the controls entirely
	 */
	public function setDefaultStyle($value): void
	{
		$this->_defaultStyle = TPropertyValue::ensureBoolean($value);
	}

	/**
	 * Registers the default stylesheet on the page, once, when `DefaultStyle` is on.
	 */
	protected function registerDefaultStyle(): void
	{
		if ($this->getDefaultStyle()) {
			$url = Prado::getApplication()->getAssetManager()->publishFilePath(__DIR__ . '/assets/consent.css');
			$this->getPage()->getClientScript()->registerStyleSheetFile('prado-consent', $url);
		}
	}
}
