<?php

/**
 * TConsentBanner class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Consent\Controls;

use belisoful\Privacy\Consent\TConsentModule;
use Prado\Prado;
use Prado\TPropertyValue;
use Prado\Web\UI\TTemplateControl;

/**
 * TConsentBanner class.
 *
 * TConsentBanner asks the visitor for consent. It renders while consent is required and the
 * visitor has not decided (`TConsentModule::getIsDecided()`), and not while the preferences are
 * open. It offers:
 *
 * | Button | Action |
 * |---|---|
 * | Reject all | `TConsentModule::rejectAll()` |
 * | Accept all | `TConsentModule::acceptAll()` (categories GPC denies stay denied) |
 * | Customize | opens `TConsentPreferences` |
 *
 * Reject all and Accept all are the same element with the same CSS class (`prado-consent-button`)
 * on the first layer, so refusing is as easy as accepting (EDPB cookie banner taskforce report,
 * 2023). No box is pre-ticked. When the request carries an honored Global Privacy Control signal,
 * the banner says so.
 *
 * The buttons are ActiveControls: with JavaScript, a click is a callback that re-renders the
 * banner, the preferences, every `TConsentGate` and every `TConsentLink` without a reload;
 * without JavaScript it is a postback. Both call the module, so the choice is stored, logged and
 * raises `onConsentChanged` the same way.
 *
 * ```xml
 * <com:TConsentBanner PolicyUrl="/privacy" />
 * ```
 *
 * Texts: `Message`, `AcceptText`, `RejectText`, `CustomizeText`, `PolicyText`, `GpcMessage`,
 * `Label`, each passed through `Prado::localize()`. `DefaultStyle="false"` drops the packaged
 * stylesheet.
 *
 * @property-read \Prado\Web\UI\ActiveControls\TActivePanel $Panel the panel a callback re-renders
 * @property-read \Prado\Web\UI\WebControls\TPlaceHolder $Body the banner's content, visible while it asks
 * @property-read \Prado\Web\UI\WebControls\TLiteral $MessageText the message
 * @property-read \Prado\Web\UI\WebControls\THyperLink $PolicyLink the privacy policy link
 * @property-read \Prado\Web\UI\WebControls\TPlaceHolder $GpcNotice the GPC notice, visible while GPC is honored
 * @property-read \Prado\Web\UI\WebControls\TLiteral $GpcText the GPC notice's text
 * @property-read \Prado\Web\UI\ActiveControls\TActiveButton $AcceptAll the accept-all button
 * @property-read \Prado\Web\UI\ActiveControls\TActiveButton $RejectAll the reject-all button
 * @property-read \Prado\Web\UI\ActiveControls\TActiveButton $Customize the button that opens the preferences
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TConsentBanner extends TTemplateControl implements IConsentControl
{
	use TConsentControlTrait;

	/** @var array<string, string> text property => default */
	public const TEXTS = [
		'Message' => 'We use cookies and similar storage for analytics, marketing and extra features, only with your consent. Necessary storage is always on. You can change your choice at any time.',
		'AcceptText' => 'Accept all',
		'RejectText' => 'Reject all',
		'CustomizeText' => 'Customize',
		'PolicyText' => 'Privacy policy',
		'GpcMessage' => 'Your browser sent a Global Privacy Control signal: marketing stays off.',
		'Label' => 'Privacy choices',
	];

	/**
	 * Registers the banner with the consent module.
	 * @param \Prado\TEventParameter $param the event parameter
	 */
	public function onInit($param)
	{
		parent::onInit($param);
		$this->getConsentModule()->registerConsentControl($this);
	}

	/**
	 * Applies the consent state and the texts before rendering.
	 * @param \Prado\TEventParameter $param the event parameter
	 */
	public function onPreRender($param)
	{
		parent::onPreRender($param);
		$module = $this->getConsentModule();
		$this->Body->setVisible($this->getIsShown());
		$this->MessageText->setText($this->getMessage());
		$this->AcceptAll->setText($this->getAcceptText());
		$this->RejectAll->setText($this->getRejectText());
		$this->Customize->setText($this->getCustomizeText());
		$this->PolicyLink->setVisible($this->getPolicyUrl() !== '');
		$this->PolicyLink->setNavigateUrl($this->getPolicyUrl());
		$this->PolicyLink->setText($this->getPolicyText());
		$this->GpcNotice->setVisible($module->getIsGpcHonored());
		$this->GpcText->setText($this->getGpcMessage());
		$this->registerDefaultStyle();
	}

	/**
	 * @return bool whether the banner asks: consent is required, undecided, and the preferences are closed
	 */
	public function getIsShown(): bool
	{
		$module = $this->getConsentModule();
		return $module->getIsConsentRequired() && !$module->getIsDecided() && !$module->getPreferencesOpen();
	}

	/**
	 * Accepts every category.
	 * @param \Prado\Web\UI\TControl $sender the button
	 * @param \Prado\TEventParameter $param the event parameter
	 */
	public function acceptAllClicked($sender, $param): void
	{
		$this->getConsentModule()->acceptAll(TConsentModule::SOURCE_BANNER);
	}

	/**
	 * Rejects every category that is not required.
	 * @param \Prado\Web\UI\TControl $sender the button
	 * @param \Prado\TEventParameter $param the event parameter
	 */
	public function rejectAllClicked($sender, $param): void
	{
		$this->getConsentModule()->rejectAll(TConsentModule::SOURCE_BANNER);
	}

	/**
	 * Opens the preferences.
	 * @param \Prado\Web\UI\TControl $sender the button
	 * @param \Prado\TEventParameter $param the event parameter
	 */
	public function customizeClicked($sender, $param): void
	{
		$this->getConsentModule()->setPreferencesOpen(true);
	}

	/**
	 * Re-renders the banner into a callback response.
	 * @param \Prado\Web\UI\THtmlWriter $writer the callback's writer
	 */
	public function refreshConsent($writer): void
	{
		$this->Panel->render($writer);
	}

	/**
	 * @return string the URL of the privacy policy; empty hides the link
	 */
	public function getPolicyUrl(): string
	{
		return $this->getViewState('PolicyUrl', '');
	}

	/**
	 * @param mixed $value the URL of the privacy policy; empty hides the link
	 */
	public function setPolicyUrl($value): void
	{
		$this->setViewState('PolicyUrl', \trim(TPropertyValue::ensureString($value)), '');
	}

	/**
	 * Reads a text property: `Message`, `AcceptText`, `RejectText`, `CustomizeText`, `PolicyText`, `GpcMessage`, `Label`.
	 * @param string $name the property
	 * @return string the configured text, or the localized default
	 */
	protected function getTextProperty(string $name): string
	{
		return $this->getViewState($name, '') ?: Prado::localize(self::TEXTS[$name]);
	}

	/**
	 * @return string the message
	 */
	public function getMessage(): string
	{
		return $this->getTextProperty('Message');
	}

	/**
	 * @param mixed $value the message; empty restores the default
	 */
	public function setMessage($value): void
	{
		$this->setViewState('Message', TPropertyValue::ensureString($value), '');
	}

	/**
	 * @return string the text of the accept-all button
	 */
	public function getAcceptText(): string
	{
		return $this->getTextProperty('AcceptText');
	}

	/**
	 * @param mixed $value the text of the accept-all button; empty restores the default
	 */
	public function setAcceptText($value): void
	{
		$this->setViewState('AcceptText', TPropertyValue::ensureString($value), '');
	}

	/**
	 * @return string the text of the reject-all button
	 */
	public function getRejectText(): string
	{
		return $this->getTextProperty('RejectText');
	}

	/**
	 * @param mixed $value the text of the reject-all button; empty restores the default
	 */
	public function setRejectText($value): void
	{
		$this->setViewState('RejectText', TPropertyValue::ensureString($value), '');
	}

	/**
	 * @return string the text of the customize button
	 */
	public function getCustomizeText(): string
	{
		return $this->getTextProperty('CustomizeText');
	}

	/**
	 * @param mixed $value the text of the customize button; empty restores the default
	 */
	public function setCustomizeText($value): void
	{
		$this->setViewState('CustomizeText', TPropertyValue::ensureString($value), '');
	}

	/**
	 * @return string the text of the privacy policy link
	 */
	public function getPolicyText(): string
	{
		return $this->getTextProperty('PolicyText');
	}

	/**
	 * @param mixed $value the text of the privacy policy link; empty restores the default
	 */
	public function setPolicyText($value): void
	{
		$this->setViewState('PolicyText', TPropertyValue::ensureString($value), '');
	}

	/**
	 * @return string the notice shown when a Global Privacy Control signal is honored
	 */
	public function getGpcMessage(): string
	{
		return $this->getTextProperty('GpcMessage');
	}

	/**
	 * @param mixed $value the notice shown when a Global Privacy Control signal is honored; empty restores the default
	 */
	public function setGpcMessage($value): void
	{
		$this->setViewState('GpcMessage', TPropertyValue::ensureString($value), '');
	}

	/**
	 * @return string the accessible name of the banner region
	 */
	public function getLabel(): string
	{
		return $this->getTextProperty('Label');
	}

	/**
	 * @param mixed $value the accessible name of the banner region; empty restores the default
	 */
	public function setLabel($value): void
	{
		$this->setViewState('Label', TPropertyValue::ensureString($value), '');
	}
}
