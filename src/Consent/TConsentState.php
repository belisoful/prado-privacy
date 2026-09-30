<?php

/**
 * TConsentState class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Consent;

use Prado\TEnumerable;

/**
 * TConsentState class.
 *
 * TConsentState enumerates the state of one consent category for a visitor.
 *
 * | State | Meaning |
 * |---|---|
 * | `granted` | the category may be used |
 * | `denied` | the visitor refused, withdrew, or a Global Privacy Control signal applies |
 * | `undecided` | consent is required and the visitor has not chosen; the category may not be used |
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TConsentState extends TEnumerable
{
	public const Granted = 'granted';
	public const Denied = 'denied';
	public const Undecided = 'undecided';
}
