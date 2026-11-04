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
use Prado\Exceptions\TInvalidDataValueException;
use Prado\Prado;
use Prado\TPropertyValue;
use Prado\Web\UI\ActiveControls\TCallbackEventParameter;

/**
 * TConsentControlTrait trait.
 *
 * TConsentControlTrait gives a consent control its module (`ConsentModuleID`, or the application's
 * first `TConsentModule`), the refresh of every consent control after a callback changed the
 * consent, the default stylesheet, the focus script (`assets/consent.js`) and URL validation.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
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

	/**
	 * @return ?TConsentPreferences the first visible preferences control registered with the module, null when the page has none
	 */
	public function getPreferencesControl(): ?TConsentPreferences
	{
		foreach ($this->getConsentModule()->getConsentControls() as $control) {
			if ($control instanceof TConsentPreferences && $control->getVisible(true)) {
				return $control;
			}
		}
		return null;
	}

	/**
	 * Registers the focus script (`assets/consent.js`) on the page, once.
	 */
	protected function registerConsentScript(): void
	{
		$url = Prado::getApplication()->getAssetManager()->publishFilePath(__DIR__ . '/assets/consent.js');
		$this->getPage()->getClientScript()->registerScriptFile('prado-consent', $url);
	}

	/**
	 * Calls a function of the focus script once a callback response has updated the page; a
	 * postback ignores it.
	 * @param string $function `open`, `close` or `chosen`
	 * @param array<int, string> $params the arguments
	 */
	protected function callConsentScript(string $function, array $params = []): void
	{
		$this->getPage()->getCallbackClient()->callClientFunction('PradoConsent.' . $function, $params);
	}

	/**
	 * Validates a URL the control renders as an `href`: http, https, or relative.
	 * @param mixed $value the URL
	 * @throws TInvalidDataValueException when the URL has another scheme, such as `javascript:`
	 * @return string the trimmed URL
	 */
	protected function ensureHttpUrl($value): string
	{
		$url = \trim(TPropertyValue::ensureString($value));
		// Browsers drop tabs, newlines and control characters from a scheme ("java\tscript:").
		$scheme = \preg_replace('/[\x00-\x20]+/', '', $url);
		if (\preg_match('/^([a-z][a-z0-9+.-]*):/i', $scheme, $match) && !\in_array(\strtolower($match[1]), ['http', 'https'], true)) {
			throw new TInvalidDataValueException('privacy_url_scheme_invalid', $url);
		}
		return $url;
	}
}
