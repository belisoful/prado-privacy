<?php

/**
 * TConsentLink class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Consent\Controls;

use belisoful\Privacy\Consent\TConsentMode;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\Prado;
use Prado\TPropertyValue;
use Prado\Web\UI\ActiveControls\TActiveLinkButton;

/**
 * TConsentLink class.
 *
 * TConsentLink reopens `TConsentPreferences`, so a visitor can change or withdraw a choice as
 * easily as they gave it (GDPR Art. 7(3)). Put one in every page's footer.
 *
 * | Mode | Text | Renders |
 * |---|---|---|
 * | `preferences` (default) | "Privacy choices" | always |
 * | `optout` | "Do Not Sell or Share My Personal Information" | only in opt-out regions (`TConsentMode::OptOut`), as CCPA §1798.135 requires there |
 *
 * `Text` overrides the default text. With JavaScript, a click is a callback that opens the
 * preferences in place. A link button needs JavaScript to post back, so `PreferencesUrl` gives the
 * link a real `href`, such as a page holding `<com:TConsentPreferences AlwaysVisible="true" />`:
 * without JavaScript the browser follows it; with JavaScript the callback runs instead.
 *
 * ```xml
 * <com:TConsentLink PreferencesUrl="/privacy-settings" />
 * <com:TConsentLink Mode="optout" PreferencesUrl="/privacy-settings" />
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TConsentLink extends TActiveLinkButton implements IConsentControl
{
	use TConsentControlTrait;

	/** The link that opens the preferences. */
	public const MODE_PREFERENCES = 'preferences';

	/** The CCPA opt-out link. */
	public const MODE_OPTOUT = 'optout';

	/** @var array<string, string> mode => default text */
	public const TEXTS = [
		self::MODE_PREFERENCES => 'Privacy choices',
		self::MODE_OPTOUT => 'Do Not Sell or Share My Personal Information',
	];

	/**
	 * Registers the link with the consent module.
	 * @param \Prado\TEventParameter $param the event parameter
	 */
	public function onInit($param)
	{
		parent::onInit($param);
		$this->setCausesValidation(false);
		$this->getConsentModule()->registerConsentControl($this);
	}

	/**
	 * Hides an opt-out link outside opt-out regions.
	 * @param \Prado\TEventParameter $param the event parameter
	 */
	public function onPreRender($param)
	{
		parent::onPreRender($param);
		$this->getAttributes()->add('data-consent-link', $this->getMode());
		$this->setVisible($this->getIsShown());
	}

	/**
	 * @return bool whether the link renders: always, or in an opt-out region for `Mode="optout"`
	 */
	public function getIsShown(): bool
	{
		return $this->getMode() !== self::MODE_OPTOUT || $this->getConsentModule()->getMode() === TConsentMode::OptOut;
	}

	/**
	 * Opens the preferences, then raises `OnClick`.
	 * @param \Prado\TEventParameter $param the event parameter
	 */
	public function onClick($param)
	{
		$this->getConsentModule()->setPreferencesOpen(true);
		parent::onClick($param);
	}

	/**
	 * Re-renders every consent control, then raises `OnCallback`.
	 * @param \Prado\Web\UI\ActiveControls\TCallbackEventParameter $param the event parameter
	 */
	public function onCallback($param)
	{
		$this->refreshConsentControls($this, $param);
		parent::onCallback($param);
	}

	/**
	 * Re-renders the link into a callback response.
	 * @param \Prado\Web\UI\THtmlWriter $writer the callback's writer
	 */
	public function refreshConsent($writer): void
	{
		$this->render($writer);
	}

	/**
	 * Renders `PreferencesUrl` as the `href` when set, and the link button's no-op otherwise.
	 * @param \Prado\Web\UI\THtmlWriter $writer the writer
	 */
	protected function renderLinkButtonHref($writer)
	{
		if ($this->getPreferencesUrl() === '') {
			parent::renderLinkButtonHref($writer);
		} else {
			$writer->addAttribute('href', $this->getPreferencesUrl());
		}
	}

	/**
	 * @return string the page a browser without JavaScript follows; empty for none
	 */
	public function getPreferencesUrl(): string
	{
		return $this->getViewState('PreferencesUrl', '');
	}

	/**
	 * @param mixed $value the page a browser without JavaScript follows, holding a `TConsentPreferences`; empty for none
	 */
	public function setPreferencesUrl($value): void
	{
		$this->setViewState('PreferencesUrl', \trim(TPropertyValue::ensureString($value)), '');
	}

	/**
	 * @return string the text; the mode's localized default when none is set
	 */
	public function getText()
	{
		return parent::getText() ?: Prado::localize(self::TEXTS[$this->getMode()]);
	}

	/**
	 * @return string `preferences` or `optout`
	 */
	public function getMode(): string
	{
		return $this->getViewState('Mode', self::MODE_PREFERENCES);
	}

	/**
	 * @param mixed $value `preferences` or `optout`
	 * @throws TInvalidDataValueException when the mode is neither
	 */
	public function setMode($value): void
	{
		$value = \strtolower(\trim(TPropertyValue::ensureString($value)));
		if (!isset(self::TEXTS[$value])) {
			throw new TInvalidDataValueException('privacy_link_mode_invalid', $value);
		}
		$this->setViewState('Mode', $value, self::MODE_PREFERENCES);
	}
}
