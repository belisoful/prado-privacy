<?php

/**
 * TConsentGate class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Consent\Controls;

use belisoful\Privacy\Consent\TConsentState;
use Prado\Exceptions\TConfigurationException;
use Prado\TPropertyValue;
use Prado\Web\UI\ActiveControls\TActivePanel;
use Prado\Web\UI\ITemplate;
use Prado\Web\UI\WebControls\TPlaceHolder;

/**
 * TConsentGate class.
 *
 * TConsentGate renders its content only while a consent `Category` is granted, so a template gates
 * a map, a video player or a social widget declaratively. Until then the content is absent from
 * the page (no iframe, no script, no request to the third party), and the gate renders its
 * `DeniedTemplate` instead: a notice, often with a `TConsentLink`.
 *
 * ```xml
 * <com:TConsentGate Category="functional">
 *   <iframe src="https://www.youtube-nocookie.com/embed/…"></iframe>
 *   <prop:DeniedTemplate>
 *     <p>This video needs functional cookies. <com:TConsentLink Text="Change your choice" /></p>
 *   </prop:DeniedTemplate>
 * </com:TConsentGate>
 * ```
 *
 * The gate is a `<div>` with `data-consent-category` and `data-consent-state`. When a callback
 * changes the consent, the gate re-renders in place.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 */
class TConsentGate extends TActivePanel implements IConsentControl
{
	use TConsentControlTrait;

	/** @var ?ITemplate the content rendered while the category is not granted */
	private ?ITemplate $_deniedTemplate = null;

	/** @var ?TPlaceHolder the instantiated denied template */
	private ?TPlaceHolder $_deniedContainer = null;

	/**
	 * Instantiates the denied template and registers the gate with the consent module.
	 * @param \Prado\TEventParameter $param the event parameter
	 * @throws TConfigurationException when `Category` is empty
	 */
	public function onInit($param)
	{
		parent::onInit($param);
		if ($this->getCategory() === '') {
			throw new TConfigurationException('privacy_gate_category_required', $this->getUniqueID());
		}
		if ($this->_deniedTemplate !== null) {
			$this->_deniedContainer = new TPlaceHolder();
			$this->_deniedTemplate->instantiateIn($this->_deniedContainer);
			$this->getControls()->add($this->_deniedContainer);
		}
		$this->getConsentModule()->registerConsentControl($this);
	}

	/**
	 * Adds the category and its state as data attributes.
	 * @param \Prado\TEventParameter $param the event parameter
	 */
	public function onPreRender($param)
	{
		parent::onPreRender($param);
		$this->getAttributes()->add('data-consent-category', $this->getCategory());
		$this->getAttributes()->add('data-consent-state', $this->getConsentModule()->getCategoryConsent($this->getCategory()));
	}

	/**
	 * @return bool whether the category is granted
	 */
	public function getIsGranted(): bool
	{
		return $this->getConsentModule()->getCategoryConsent($this->getCategory()) === TConsentState::Granted;
	}

	/**
	 * Renders the content while the category is granted, and the denied template otherwise.
	 * @param \Prado\Web\UI\THtmlWriter $writer the writer
	 */
	public function renderContents($writer)
	{
		if (!$this->getIsGranted()) {
			$this->_deniedContainer?->renderControl($writer);
			return;
		}
		// The framework renders every kind of child (controls, strings, template literals); the
		// denied template is hidden while it does.
		$this->_deniedContainer?->setVisible(false);
		parent::renderContents($writer);
		$this->_deniedContainer?->setVisible(true);
	}

	/**
	 * Re-renders the gate into a callback response.
	 * @param \Prado\Web\UI\THtmlWriter $writer the callback's writer
	 */
	public function refreshConsent($writer): void
	{
		$this->render($writer);
	}

	/**
	 * @return string the consent category the content needs
	 */
	public function getCategory(): string
	{
		return $this->getViewState('Category', '');
	}

	/**
	 * @param mixed $value the consent category the content needs
	 */
	public function setCategory($value): void
	{
		$this->setViewState('Category', \strtolower(\trim(TPropertyValue::ensureString($value))), '');
	}

	/**
	 * @return ?ITemplate the content rendered while the category is not granted
	 */
	public function getDeniedTemplate(): ?ITemplate
	{
		return $this->_deniedTemplate;
	}

	/**
	 * @param ?ITemplate $value the content rendered while the category is not granted
	 */
	public function setDeniedTemplate(?ITemplate $value): void
	{
		$this->_deniedTemplate = $value;
	}
}
