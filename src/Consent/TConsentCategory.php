<?php

/**
 * TConsentCategory class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Consent;

use Prado\Exceptions\TInvalidDataValueException;
use Prado\TComponent;
use Prado\TPropertyValue;

/**
 * TConsentCategory class.
 *
 * TConsentCategory describes one purpose a visitor consents to: its `Name` (the key in
 * `TConsentModule::getConsent()`), a `Title` and `Description` for the preferences, and whether it
 * is `Required`. A required category is always granted and its checkbox is disabled; it covers
 * storage that is strictly necessary for a service the visitor asked for (ePrivacy Art. 5(3)).
 *
 * `TConsentModule` declares `necessary` (required), `analytics`, `marketing`, `functional` and
 * `personalization`. An application adds or retitles one with a `<category>` element:
 *
 * ```xml
 * <module id="belisoful/prado-privacy">
 *   <category Name="video" Title="Video embeds" Description="YouTube and Vimeo players." />
 * </module>
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 */
class TConsentCategory extends TComponent
{
	/** The pattern a category name matches: lowercase, a letter first, then letters, digits and underscores. */
	public const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/';

	/** @var string the category name */
	private string $_name = '';

	/** @var string the title shown to the visitor */
	private string $_title = '';

	/** @var string the description shown to the visitor */
	private string $_description = '';

	/** @var bool whether the category is always granted */
	private bool $_required = false;

	/**
	 * @param string $name the category name
	 * @param string $title the title shown to the visitor; empty uses the name
	 * @param string $description the description shown to the visitor
	 * @param bool $required whether the category is always granted
	 */
	public function __construct(string $name = '', string $title = '', string $description = '', bool $required = false)
	{
		if ($name !== '') {
			$this->setName($name);
		}
		$this->_title = $title;
		$this->_description = $description;
		$this->_required = $required;
		parent::__construct();
	}

	/**
	 * @return string the category name, the key in `TConsentModule::getConsent()`
	 */
	public function getName(): string
	{
		return $this->_name;
	}

	/**
	 * @param mixed $value the category name: lowercase, a letter first, up to 32 letters, digits and underscores
	 * @throws TInvalidDataValueException when the name does not match {@see NAME_PATTERN}
	 * @return static the category
	 */
	public function setName($value): static
	{
		$value = \strtolower(\trim(TPropertyValue::ensureString($value)));
		if (!\preg_match(self::NAME_PATTERN, $value)) {
			throw new TInvalidDataValueException('privacy_category_name_invalid', $value);
		}
		$this->_name = $value;
		return $this;
	}

	/**
	 * @return string the title shown to the visitor; the name when none is set
	 */
	public function getTitle(): string
	{
		return $this->_title !== '' ? $this->_title : \ucfirst($this->_name);
	}

	/**
	 * @param mixed $value the title shown to the visitor
	 * @return static the category
	 */
	public function setTitle($value): static
	{
		$this->_title = \trim(TPropertyValue::ensureString($value));
		return $this;
	}

	/**
	 * @return string the description shown to the visitor
	 */
	public function getDescription(): string
	{
		return $this->_description;
	}

	/**
	 * @param mixed $value the description shown to the visitor
	 * @return static the category
	 */
	public function setDescription($value): static
	{
		$this->_description = \trim(TPropertyValue::ensureString($value));
		return $this;
	}

	/**
	 * @return bool whether the category is always granted
	 */
	public function getRequired(): bool
	{
		return $this->_required;
	}

	/**
	 * @param mixed $value whether the category is always granted
	 * @return static the category
	 */
	public function setRequired($value): static
	{
		$this->_required = TPropertyValue::ensureBoolean($value);
		return $this;
	}
}
