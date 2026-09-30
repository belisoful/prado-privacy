<?php

/**
 * TRetentionPolicy class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Retention;

use belisoful\Privacy\TConfiguredComponentTrait;
use Prado\Exceptions\TConfigurationException;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\TComponent;
use Prado\TPropertyValue;

/**
 * TRetentionPolicy class.
 *
 * TRetentionPolicy says how long one kind of data is kept (GDPR Art. 5(1)(e), storage limitation)
 * and which `IRetentionPurger` removes it: `Name` (the kind of data, referenced by records of
 * processing), `Period` (an ISO 8601 duration: `P30D`, `P6M`, `P3Y`, `PT12H`; `P0D` purges
 * everything already past its own expiry), `Purger` and `Description`.
 *
 * ```xml
 * <policy Name="orders" Period="P10Y" Description="Invoices: commercial and tax law">
 *   <purger class="belisoful\Privacy\Retention\TDbRetentionPurger" ConnectionID="db" Table="orders" TimeColumn="created_at" />
 * </policy>
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TRetentionPolicy extends TComponent
{
	use TConfiguredComponentTrait;

	/** @var string the kind of data */
	private string $_name = '';

	/** @var string the ISO 8601 duration */
	private string $_period = '';

	/** @var mixed the purger, or its configuration until first use */
	private mixed $_purger = null;

	/** @var string the description */
	private string $_description = '';

	/**
	 * @param string $name the kind of data
	 * @param string $period the ISO 8601 duration; empty to set later
	 * @param mixed $purger the purger, a configuration array, or a module id
	 * @param string $description the description
	 */
	public function __construct(string $name = '', string $period = '', mixed $purger = null, string $description = '')
	{
		$this->_name = $name;
		if ($period !== '') {
			$this->setPeriod($period);
		}
		$this->_purger = $purger;
		$this->_description = $description;
		parent::__construct();
	}

	/**
	 * @param int $now the current Unix time
	 * @throws TConfigurationException when no period is set
	 * @return int the Unix time before which data is removed: `$now` minus `Period`
	 */
	public function getCutoff(int $now): int
	{
		if ($this->_period === '') {
			throw new TConfigurationException('privacy_retention_period_missing', $this->_name);
		}
		return (new \DateTimeImmutable('@' . $now))->sub(new \DateInterval($this->_period))->getTimestamp();
	}

	/**
	 * @return string the kind of data
	 */
	public function getName(): string
	{
		return $this->_name;
	}

	/**
	 * @param mixed $value the kind of data
	 * @return static the policy
	 */
	public function setName($value): static
	{
		$this->_name = \trim(TPropertyValue::ensureString($value));
		return $this;
	}

	/**
	 * @return string the ISO 8601 duration
	 */
	public function getPeriod(): string
	{
		return $this->_period;
	}

	/**
	 * @param mixed $value an ISO 8601 duration (`P3Y`, `P6M`, `P30D`, `PT12H`)
	 * @throws TInvalidDataValueException when the value is not an ISO 8601 duration
	 * @return static the policy
	 */
	public function setPeriod($value): static
	{
		$value = \strtoupper(\trim(TPropertyValue::ensureString($value)));
		try {
			new \DateInterval($value);
		} catch (\Exception $e) {
			throw new TInvalidDataValueException('privacy_retention_period_invalid', $value);
		}
		$this->_period = $value;
		return $this;
	}

	/**
	 * @throws TConfigurationException when no purger is set, or it does not implement `IRetentionPurger`
	 * @return IRetentionPurger the purger
	 */
	public function getPurger(): IRetentionPurger
	{
		if (!($this->_purger instanceof IRetentionPurger)) {
			if ($this->_purger === null) {
				throw new TConfigurationException('privacy_retention_purger_missing', $this->_name);
			}
			$this->_purger = $this->resolveConfiguredComponent($this->_purger, IRetentionPurger::class);
		}
		return $this->_purger;
	}

	/**
	 * @param mixed $value a purger, a configuration array (`class` and properties), or a module id
	 * @return static the policy
	 */
	public function setPurger($value): static
	{
		$this->_purger = ($value === '' ? null : $value);
		return $this;
	}

	/**
	 * @return string the description
	 */
	public function getDescription(): string
	{
		return $this->_description;
	}

	/**
	 * @param mixed $value the description: why the data is kept this long
	 * @return static the policy
	 */
	public function setDescription($value): static
	{
		$this->_description = \trim(TPropertyValue::ensureString($value));
		return $this;
	}
}
