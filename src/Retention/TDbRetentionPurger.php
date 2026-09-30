<?php

/**
 * TDbRetentionPurger class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Retention;

use belisoful\Privacy\Data\TPrivacyDbConnectionTrait;
use Prado\Exceptions\TConfigurationException;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\TComponent;
use Prado\TPropertyValue;

/**
 * TDbRetentionPurger class.
 *
 * TDbRetentionPurger deletes the rows of `Table` whose `TimeColumn` is before the cutoff, and that
 * match the optional `Condition` (an SQL expression from the configuration, never from a request).
 * `TimeFormat` says how the column stores time: `unix` (integer seconds, the default) or
 * `datetime` (`Y-m-d H:i:s`, UTC).
 *
 * ```xml
 * <purger class="belisoful\Privacy\Retention\TDbRetentionPurger" ConnectionID="db"
 *     Table="newsletter_bounces" TimeColumn="bounced_at" TimeFormat="datetime" Condition="resolved = 1" />
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TDbRetentionPurger extends TComponent implements IRetentionPurger
{
	use TPrivacyDbConnectionTrait;

	/** The pattern of a table or column name: letters, digits and underscores, optionally schema-qualified. */
	public const IDENTIFIER_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/';

	/** @var string the table */
	private string $_table = '';

	/** @var string the time column */
	private string $_timeColumn = '';

	/** @var string the time format */
	private string $_timeFormat = 'unix';

	/** @var string the extra condition */
	private string $_condition = '';

	/**
	 * Deletes the rows before the cutoff.
	 * @param int $before a Unix time
	 * @throws TConfigurationException when `Table` or `TimeColumn` is not set
	 * @return int the rows deleted
	 */
	public function purge(int $before): int
	{
		if ($this->_table === '' || $this->_timeColumn === '') {
			throw new TConfigurationException('privacy_purger_table_missing', static::class);
		}
		$sql = 'DELETE FROM ' . $this->_table . ' WHERE ' . $this->_timeColumn . ' < :before'
			. ($this->_condition === '' ? '' : ' AND (' . $this->_condition . ')');
		$command = $this->getDbConnection()->createCommand($sql);
		$command->bindValue(':before', $this->_timeFormat === 'datetime' ? \gmdate('Y-m-d H:i:s', $before) : $before);
		return (int) $command->execute();
	}

	/**
	 * @return string the table
	 */
	public function getTable(): string
	{
		return $this->_table;
	}

	/**
	 * @param mixed $value the table
	 * @throws TInvalidDataValueException when the value is not a table name
	 * @return static the purger
	 */
	public function setTable($value): static
	{
		$this->_table = self::ensureIdentifier($value);
		return $this;
	}

	/**
	 * @return string the time column
	 */
	public function getTimeColumn(): string
	{
		return $this->_timeColumn;
	}

	/**
	 * @param mixed $value the column holding each row's time
	 * @throws TInvalidDataValueException when the value is not a column name
	 * @return static the purger
	 */
	public function setTimeColumn($value): static
	{
		$this->_timeColumn = self::ensureIdentifier($value);
		return $this;
	}

	/**
	 * @return string `unix` or `datetime`
	 */
	public function getTimeFormat(): string
	{
		return $this->_timeFormat;
	}

	/**
	 * @param mixed $value `unix` (integer seconds) or `datetime` (`Y-m-d H:i:s`, UTC)
	 * @throws TInvalidDataValueException when the value is neither
	 * @return static the purger
	 */
	public function setTimeFormat($value): static
	{
		$value = \strtolower(\trim(TPropertyValue::ensureString($value)));
		if (!\in_array($value, ['unix', 'datetime'], true)) {
			throw new TInvalidDataValueException('privacy_purger_time_format_invalid', $value);
		}
		$this->_timeFormat = $value;
		return $this;
	}

	/**
	 * @return string the extra condition
	 */
	public function getCondition(): string
	{
		return $this->_condition;
	}

	/**
	 * @param mixed $value an SQL condition the rows must also match; from the configuration only
	 * @return static the purger
	 */
	public function setCondition($value): static
	{
		$this->_condition = \trim(TPropertyValue::ensureString($value));
		return $this;
	}

	/**
	 * @param mixed $value a table or column name
	 * @throws TInvalidDataValueException when the value is not an identifier
	 * @return string the identifier
	 */
	protected static function ensureIdentifier(mixed $value): string
	{
		$value = \trim(TPropertyValue::ensureString($value));
		if (!\preg_match(self::IDENTIFIER_PATTERN, $value)) {
			throw new TInvalidDataValueException('privacy_purger_identifier_invalid', $value);
		}
		return $value;
	}
}
