<?php

/**
 * TConsentModule class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Consent;

use Prado\Exceptions\TInvalidDataValueException;
use Prado\TModule;
use Prado\TPropertyValue;

/**
 * TConsentModule class.
 *
 * TConsentModule holds the visitor's consent per category for the request. `Version` names the
 * privacy policy the choices were made under; a choice recorded under another version counts as
 * undecided.
 *
 * ```xml
 * <module id="belisoful/prado-privacy" Version="2026-09" />
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TConsentModule extends TModule
{
	/** The version a module without a configured one reports. */
	public const DEFAULT_VERSION = '1';

	/** @var string the privacy policy version */
	private string $_version = self::DEFAULT_VERSION;

	/**
	 * @return string the privacy policy version the visitor's choice must match
	 */
	public function getVersion(): string
	{
		return $this->_version;
	}

	/**
	 * @param mixed $value the privacy policy version, a non-empty string of at most 64 characters
	 * @throws TInvalidDataValueException when the value is empty or longer than 64 characters
	 * @return static the module
	 */
	public function setVersion($value): static
	{
		$value = \trim(TPropertyValue::ensureString($value));
		if ($value === '' || \strlen($value) > 64) {
			throw new TInvalidDataValueException('privacy_version_invalid', $value);
		}
		$this->_version = $value;
		return $this;
	}
}
