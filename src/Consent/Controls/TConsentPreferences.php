<?php

/**
 * TConsentPreferences class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Consent\Controls;

use belisoful\Privacy\Consent\TConsentModule;
use belisoful\Privacy\Consent\TConsentState;
use Prado\Prado;
use Prado\TPropertyValue;
use Prado\Web\UI\TTemplateControl;
use Prado\Web\UI\WebControls\TCheckBox;
use Prado\Web\UI\WebControls\TLiteral;
use Prado\Web\UI\WebControls\TPanel;

/**
 * TConsentPreferences class.
 *
 * TConsentPreferences lets the visitor choose per category: one checkbox per
 * `TConsentModule` category with its title and description, checked when the category is granted.
 * Required categories are checked and disabled. Save records the checkboxes with the source
 * `preferences`, so a grant made here stands against a Global Privacy Control signal (CCPA
 * regulations §7025(c)(3)). Reject all and Accept all are offered here too.
 *
 * The preferences are hidden until a `TConsentLink` or the banner's Customize opens them, unless
 * `AlwaysVisible` is on (for a dedicated privacy settings page). Saving closes them. Like the
 * banner, every button works by callback and by postback.
 *
 * ```xml
 * <com:TConsentPreferences />
 * <com:TConsentPreferences AlwaysVisible="true" Title="Your privacy settings" />
 * ```
 *
 * A checkbox's ID is `Category_<name>`; its wrapper carries `data-consent-category="<name>"`.
 *
 * @property-read \Prado\Web\UI\ActiveControls\TActivePanel $Panel the panel a callback re-renders
 * @property-read \Prado\Web\UI\WebControls\TPlaceHolder $Body the preferences' content, visible while open
 * @property-read \Prado\Web\UI\WebControls\TLiteral $TitleText the title
 * @property-read \Prado\Web\UI\WebControls\TPlaceHolder $Categories the category checkboxes
 * @property-read \Prado\Web\UI\ActiveControls\TActiveButton $Save the save button
 * @property-read \Prado\Web\UI\ActiveControls\TActiveButton $AcceptAll the accept-all button
 * @property-read \Prado\Web\UI\ActiveControls\TActiveButton $RejectAll the reject-all button
 * @property-read \Prado\Web\UI\ActiveControls\TActiveButton $Close the close button
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TConsentPreferences extends TTemplateControl implements IConsentControl
{
	use TConsentControlTrait;

	/** @var array<string, string> text property => default */
	public const TEXTS = [
		'Title' => 'Privacy preferences',
		'SaveText' => 'Save choices',
		'AcceptText' => 'Accept all',
		'RejectText' => 'Reject all',
		'CloseText' => 'Close',
	];

	/** The prefix of a category checkbox's ID. */
	public const CHECKBOX_PREFIX = 'Category_';

	/** @var array<string, TCheckBox> category => checkbox */
	private array $_checkBoxes = [];

	/**
	 * Builds a checkbox per category and registers with the consent module.
	 * @param \Prado\TEventParameter $param the event parameter
	 */
	public function onInit($param)
	{
		parent::onInit($param);
		$module = $this->getConsentModule();
		foreach ($module->getCategories() as $name => $category) {
			$wrapper = new TPanel();
			$wrapper->setCssClass('prado-consent-category');
			$wrapper->getAttributes()->add('data-consent-category', $name);
			$checkBox = new TCheckBox();
			$checkBox->setID(self::CHECKBOX_PREFIX . $name);
			$checkBox->setText($category->getTitle());
			$checkBox->setEnabled(!$category->getRequired());
			$wrapper->getControls()->add($checkBox);
			if ($category->getDescription() !== '') {
				$description = new TLiteral();
				$description->setEncode(true);
				$description->setText($category->getDescription());
				$wrapper->getControls()->add('<p class="prado-consent-description">');
				$wrapper->getControls()->add($description);
				$wrapper->getControls()->add('</p>');
			}
			$this->Categories->getControls()->add($wrapper);
			$this->_checkBoxes[$name] = $checkBox;
		}
		$module->registerConsentControl($this);
	}

	/**
	 * Applies the consent state and the texts before rendering.
	 * @param \Prado\TEventParameter $param the event parameter
	 */
	public function onPreRender($param)
	{
		parent::onPreRender($param);
		$consent = $this->getConsentModule()->getConsent();
		foreach ($this->_checkBoxes as $name => $checkBox) {
			$checkBox->setChecked(($consent[$name] ?? null) === TConsentState::Granted);
		}
		$this->Body->setVisible($this->getIsShown());
		$this->TitleText->setText($this->getTitle());
		$this->Save->setText($this->getSaveText());
		$this->AcceptAll->setText($this->getAcceptText());
		$this->RejectAll->setText($this->getRejectText());
		$this->Close->setText($this->getCloseText());
		$this->Close->setVisible(!$this->getAlwaysVisible());
		$this->registerDefaultStyle();
	}

	/**
	 * @return bool whether the preferences render: `AlwaysVisible`, or opened for this request
	 */
	public function getIsShown(): bool
	{
		return $this->getAlwaysVisible() || $this->getConsentModule()->getPreferencesOpen();
	}

	/**
	 * @return array<string, TCheckBox> category => checkbox
	 */
	public function getCheckBoxes(): array
	{
		return $this->_checkBoxes;
	}

	/**
	 * Records the checkboxes and closes the preferences.
	 * @param \Prado\Web\UI\TControl $sender the button
	 * @param \Prado\TEventParameter $param the event parameter
	 */
	public function saveClicked($sender, $param): void
	{
		$choice = [];
		foreach ($this->_checkBoxes as $name => $checkBox) {
			$choice[$name] = $checkBox->getChecked();
		}
		$module = $this->getConsentModule();
		$module->setConsent($choice, TConsentModule::SOURCE_PREFERENCES);
		$module->setPreferencesOpen(false);
	}

	/**
	 * Accepts every category and closes the preferences.
	 * @param \Prado\Web\UI\TControl $sender the button
	 * @param \Prado\TEventParameter $param the event parameter
	 */
	public function acceptAllClicked($sender, $param): void
	{
		$module = $this->getConsentModule();
		$module->acceptAll(TConsentModule::SOURCE_BANNER);
		$module->setPreferencesOpen(false);
	}

	/**
	 * Rejects every category that is not required and closes the preferences.
	 * @param \Prado\Web\UI\TControl $sender the button
	 * @param \Prado\TEventParameter $param the event parameter
	 */
	public function rejectAllClicked($sender, $param): void
	{
		$module = $this->getConsentModule();
		$module->rejectAll(TConsentModule::SOURCE_BANNER);
		$module->setPreferencesOpen(false);
	}

	/**
	 * Closes the preferences without a choice.
	 * @param \Prado\Web\UI\TControl $sender the button
	 * @param \Prado\TEventParameter $param the event parameter
	 */
	public function closeClicked($sender, $param): void
	{
		$this->getConsentModule()->setPreferencesOpen(false);
	}

	/**
	 * Re-renders the preferences into a callback response.
	 * @param \Prado\Web\UI\THtmlWriter $writer the callback's writer
	 */
	public function refreshConsent($writer): void
	{
		$this->Panel->render($writer);
	}

	/**
	 * @return bool whether the preferences always render
	 */
	public function getAlwaysVisible(): bool
	{
		return $this->getViewState('AlwaysVisible', false);
	}

	/**
	 * @param mixed $value whether the preferences always render, for a dedicated settings page
	 */
	public function setAlwaysVisible($value): void
	{
		$this->setViewState('AlwaysVisible', TPropertyValue::ensureBoolean($value), false);
	}

	/**
	 * Reads a text property: `Title`, `SaveText`, `AcceptText`, `RejectText`, `CloseText`.
	 * @param string $name the property
	 * @return string the configured text, or the localized default
	 */
	protected function getTextProperty(string $name): string
	{
		return $this->getViewState($name, '') ?: Prado::localize(self::TEXTS[$name]);
	}

	/**
	 * @return string the title
	 */
	public function getTitle(): string
	{
		return $this->getTextProperty('Title');
	}

	/**
	 * @param mixed $value the title; empty restores the default
	 */
	public function setTitle($value): void
	{
		$this->setViewState('Title', TPropertyValue::ensureString($value), '');
	}

	/**
	 * @return string the text of the save button
	 */
	public function getSaveText(): string
	{
		return $this->getTextProperty('SaveText');
	}

	/**
	 * @param mixed $value the text of the save button; empty restores the default
	 */
	public function setSaveText($value): void
	{
		$this->setViewState('SaveText', TPropertyValue::ensureString($value), '');
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
	 * @return string the text of the close button
	 */
	public function getCloseText(): string
	{
		return $this->getTextProperty('CloseText');
	}

	/**
	 * @param mixed $value the text of the close button; empty restores the default
	 */
	public function setCloseText($value): void
	{
		$this->setViewState('CloseText', TPropertyValue::ensureString($value), '');
	}
}
