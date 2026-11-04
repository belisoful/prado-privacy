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
use Prado\Exceptions\TConfigurationException;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\Prado;
use Prado\TPropertyValue;
use Prado\Web\UI\ActiveControls\TActiveLinkButton;
use Prado\Web\UI\WebControls\TWebControl;

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
 * `Text` overrides the default text; it is HTML-encoded unless `Encode` is turned off. With
 * JavaScript, a click is a callback that opens the preferences in place and moves the focus to
 * their heading; closing them returns the focus to the link. A link button needs JavaScript to post
 * back, so `PreferencesUrl` gives the link a real `href`, such as a page holding
 * `<com:TConsentPreferences AlwaysVisible="true" />`: without JavaScript the browser follows it;
 * with JavaScript the callback runs instead.
 *
 * On a page without a `TConsentPreferences` the link is a plain link to `PreferencesUrl`, and
 * rendering it without a `PreferencesUrl` throws a `TConfigurationException`, since the visitor
 * could not withdraw there. `PreferencesUrl` is http, https or relative.
 *
 * ```xml
 * <com:TConsentLink PreferencesUrl="/privacy-settings" />
 * <com:TConsentLink Mode="optout" PreferencesUrl="/privacy-settings" />
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
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
		$this->registerConsentScript();
	}

	/**
	 * @return bool whether the link renders: always, or in an opt-out region for `Mode="optout"`
	 */
	public function getIsShown(): bool
	{
		return $this->getMode() !== self::MODE_OPTOUT || $this->getConsentModule()->getMode() === TConsentMode::OptOut;
	}

	/**
	 * Opens the preferences and moves the focus to their heading, then raises `OnClick`.
	 * @param \Prado\TEventParameter $param the event parameter
	 */
	public function onClick($param)
	{
		if (($preferences = $this->getPreferencesControl()) !== null) {
			$this->getConsentModule()->setPreferencesOpen(true);
			$this->callConsentScript('open', [$preferences->getHeadingID(), $this->getClientID()]);
		}
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
	 * Renders a callback link while the page has a `TConsentPreferences`, and a plain link to
	 * `PreferencesUrl` otherwise.
	 * @param \Prado\Web\UI\THtmlWriter $writer the writer
	 * @throws TConfigurationException when the page has no `TConsentPreferences` and no `PreferencesUrl` is set
	 */
	protected function addAttributesToRender($writer)
	{
		if ($this->getPreferencesControl() !== null) {
			parent::addAttributesToRender($writer);
			return;
		}
		if ($this->getPreferencesUrl() === '') {
			throw new TConfigurationException('privacy_preferences_unreachable', 'TConsentLink', $this->getUniqueID());
		}
		$writer->addAttribute('id', $this->getClientID());
		TWebControl::addAttributesToRender($writer);
		$writer->addAttribute('href', $this->getPreferencesUrl());
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
	 * @return string the page a browser without JavaScript, or a page without `TConsentPreferences`, follows; empty for none
	 */
	public function getPreferencesUrl(): string
	{
		return $this->getViewState('PreferencesUrl', '');
	}

	/**
	 * @param mixed $value the page holding a `TConsentPreferences`, for a browser without JavaScript or a page without one; empty for none
	 * @throws TInvalidDataValueException when the URL is neither http, https nor relative
	 */
	public function setPreferencesUrl($value): void
	{
		$this->setViewState('PreferencesUrl', $this->ensureHttpUrl($value), '');
	}

	/**
	 * @return bool whether the text is HTML-encoded; defaults to true
	 */
	public function getEncode()
	{
		return $this->getViewState('Encode', true);
	}

	/**
	 * @param mixed $value whether the text is HTML-encoded; turn it off only for trusted markup
	 */
	public function setEncode($value)
	{
		$this->setViewState('Encode', TPropertyValue::ensureBoolean($value), true);
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
