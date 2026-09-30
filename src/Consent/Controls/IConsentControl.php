<?php

/**
 * IConsentControl interface file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Consent\Controls;

/**
 * IConsentControl interface.
 *
 * IConsentControl is a control that renders by the visitor's consent. It registers with
 * `TConsentModule::registerConsentControl()` when it initializes; a callback that changes the
 * consent calls {@see refreshConsent()} on every registered control, so the page updates without a
 * reload.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
interface IConsentControl
{
	/**
	 * Re-renders the control into a callback response.
	 * @param \Prado\Web\UI\THtmlWriter $writer the callback's writer
	 */
	public function refreshConsent($writer): void;
}
