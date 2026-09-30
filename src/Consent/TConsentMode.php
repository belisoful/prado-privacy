<?php

/**
 * TConsentMode class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Consent;

use Prado\TEnumerable;

/**
 * TConsentMode class.
 *
 * TConsentMode enumerates the legal regime `TConsentModule` applies to a visitor's region.
 *
 * | Mode | Region | Categories before a choice | Banner |
 * |---|---|---|---|
 * | `optin` | in `RequireConsentFor`, or unknown | undecided (not granted) | shown |
 * | `optout` | in `OptOutFor` | granted, except where GPC applies | not shown |
 * | `none` | anywhere else | granted, except where GPC applies | not shown |
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 */
class TConsentMode extends TEnumerable
{
	public const OptIn = 'optin';
	public const OptOut = 'optout';
	public const None = 'none';
}
