<?php

/**
 * TGpcMode class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Consent;

use Prado\TEnumerable;

/**
 * TGpcMode class.
 *
 * TGpcMode enumerates where `TConsentModule` honors the Global Privacy Control signal
 * (`Sec-GPC: 1`).
 *
 * | Mode | Honored in |
 * |---|---|
 * | `everywhere` | every consent mode (the default) |
 * | `required` | the opt-out regions only, where the law requires it (CCPA regulations §7025) |
 * | `off` | nowhere |
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 */
class TGpcMode extends TEnumerable
{
	public const Everywhere = 'everywhere';
	public const Required = 'required';
	public const Off = 'off';
}
