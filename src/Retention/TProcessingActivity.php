<?php

/**
 * TProcessingActivity class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Retention;

use Prado\Exceptions\TInvalidDataValueException;
use Prado\TComponent;
use Prado\TPropertyValue;

/**
 * TProcessingActivity class.
 *
 * TProcessingActivity is one entry of the records of processing activities (GDPR Art. 30(1)):
 *
 * | Property | Art. 30(1) |
 * |---|---|
 * | `Name`, `Purpose` | (b) the purposes |
 * | `LegalBasis`, `LegalBasisNote` | Art. 6(1), with the detail a basis needs (the interest pursued) |
 * | `SubjectCategories`, `DataCategories` | (c) the categories of data subjects and of personal data |
 * | `Recipients` | (d) the recipients |
 * | `Transfers` | (e) transfers to third countries and their safeguards |
 * | `RetentionPolicy`, `RetentionPeriod` | (f) the time limits for erasure |
 * | `SecurityMeasures` | (g) the technical and organisational security measures |
 *
 * List properties take a comma-separated string or an array.
 *
 * ```xml
 * <activity Name="newsletter" Purpose="Sending the newsletter" LegalBasis="consent"
 *     SubjectCategories="subscribers" DataCategories="email address, subscription date"
 *     Recipients="Mailgun (processor)" Transfers="US: EU-US Data Privacy Framework" RetentionPolicy="newsletter" />
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 */
class TProcessingActivity extends TComponent
{
	/** @var array<string, mixed> property => value */
	private array $_values = [
		'Name' => '',
		'Purpose' => '',
		'LegalBasis' => '',
		'LegalBasisNote' => '',
		'SubjectCategories' => [],
		'DataCategories' => [],
		'Recipients' => [],
		'Transfers' => [],
		'RetentionPolicy' => '',
		'RetentionPeriod' => '',
		'SecurityMeasures' => '',
	];

	/**
	 * @param array<string, mixed> $properties property => value
	 * @throws TInvalidDataValueException when the legal basis is unknown
	 */
	public function __construct(array $properties = [])
	{
		parent::__construct();
		foreach ($properties as $property => $value) {
			$this->setSubProperty($property, $value);
		}
	}

	/**
	 * @return string the activity's name, unique in the registry
	 */
	public function getName(): string
	{
		return $this->_values['Name'];
	}

	/**
	 * @param mixed $value the activity's name
	 */
	public function setName($value): void
	{
		$this->_values['Name'] = \trim(TPropertyValue::ensureString($value));
	}

	/**
	 * @return string the purpose
	 */
	public function getPurpose(): string
	{
		return $this->_values['Purpose'];
	}

	/**
	 * @param mixed $value the purpose
	 */
	public function setPurpose($value): void
	{
		$this->_values['Purpose'] = \trim(TPropertyValue::ensureString($value));
	}

	/**
	 * @return string the legal basis ({@see TLegalBasis}); empty when unset
	 */
	public function getLegalBasis(): string
	{
		return $this->_values['LegalBasis'];
	}

	/**
	 * @param mixed $value a {@see TLegalBasis} value or constant name
	 * @throws TInvalidDataValueException when the value names no basis
	 */
	public function setLegalBasis($value): void
	{
		$basis = TLegalBasis::normalize($value);
		if ($basis === null) {
			throw new TInvalidDataValueException('privacy_legal_basis_invalid', (string) $value);
		}
		$this->_values['LegalBasis'] = $basis;
	}

	/**
	 * @return string the detail of the basis: the legitimate interest, the law of the obligation
	 */
	public function getLegalBasisNote(): string
	{
		return $this->_values['LegalBasisNote'];
	}

	/**
	 * @param mixed $value the detail of the basis
	 */
	public function setLegalBasisNote($value): void
	{
		$this->_values['LegalBasisNote'] = \trim(TPropertyValue::ensureString($value));
	}

	/**
	 * @return string[] the categories of data subjects
	 */
	public function getSubjectCategories(): array
	{
		return $this->_values['SubjectCategories'];
	}

	/**
	 * @param mixed $value the categories of data subjects
	 */
	public function setSubjectCategories($value): void
	{
		$this->_values['SubjectCategories'] = self::ensureList($value);
	}

	/**
	 * @return string[] the categories of personal data
	 */
	public function getDataCategories(): array
	{
		return $this->_values['DataCategories'];
	}

	/**
	 * @param mixed $value the categories of personal data
	 */
	public function setDataCategories($value): void
	{
		$this->_values['DataCategories'] = self::ensureList($value);
	}

	/**
	 * @return string[] the recipients
	 */
	public function getRecipients(): array
	{
		return $this->_values['Recipients'];
	}

	/**
	 * @param mixed $value the recipients
	 */
	public function setRecipients($value): void
	{
		$this->_values['Recipients'] = self::ensureList($value);
	}

	/**
	 * @return string[] the transfers to third countries, with their safeguards
	 */
	public function getTransfers(): array
	{
		return $this->_values['Transfers'];
	}

	/**
	 * @param mixed $value the transfers to third countries, with their safeguards
	 */
	public function setTransfers($value): void
	{
		$this->_values['Transfers'] = self::ensureList($value);
	}

	/**
	 * @return string the name of the `TRetentionPolicy` that removes the data
	 */
	public function getRetentionPolicy(): string
	{
		return $this->_values['RetentionPolicy'];
	}

	/**
	 * @param mixed $value the name of the `TRetentionPolicy` that removes the data
	 */
	public function setRetentionPolicy($value): void
	{
		$this->_values['RetentionPolicy'] = \trim(TPropertyValue::ensureString($value));
	}

	/**
	 * @return string the retention period in words, for data no policy purges (`until the account is closed`)
	 */
	public function getRetentionPeriod(): string
	{
		return $this->_values['RetentionPeriod'];
	}

	/**
	 * @param mixed $value the retention period in words
	 */
	public function setRetentionPeriod($value): void
	{
		$this->_values['RetentionPeriod'] = \trim(TPropertyValue::ensureString($value));
	}

	/**
	 * @return string the security measures
	 */
	public function getSecurityMeasures(): string
	{
		return $this->_values['SecurityMeasures'];
	}

	/**
	 * @param mixed $value the technical and organisational security measures
	 */
	public function setSecurityMeasures($value): void
	{
		$this->_values['SecurityMeasures'] = \trim(TPropertyValue::ensureString($value));
	}

	/**
	 * @return array<string, mixed> property => value
	 */
	public function toArray(): array
	{
		return $this->_values;
	}

	/**
	 * @param mixed $value a comma-separated string or an array
	 * @return string[] the trimmed, non-empty entries
	 */
	protected static function ensureList(mixed $value): array
	{
		$list = \is_array($value) ? $value : \explode(',', TPropertyValue::ensureString($value));
		return \array_values(\array_filter(\array_map(fn ($entry) => \trim((string) $entry), $list), fn ($entry) => $entry !== ''));
	}
}
