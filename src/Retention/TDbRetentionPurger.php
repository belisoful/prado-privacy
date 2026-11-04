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
use Prado\Data\TDbConnection;
use Prado\Exceptions\TConfigurationException;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\TComponent;
use Prado\TPropertyValue;
use Prado\Util\Clock\TApplicationClockAwareTrait;

/**
 * TDbRetentionPurger class.
 *
 * TDbRetentionPurger deletes the rows of `Table` whose `TimeColumn` is before the cutoff, and that
 * match the optional `Condition` (an SQL expression from the configuration, never from a request).
 * `Table`, `TimeColumn` and `KeyColumn` are validated identifiers, quoted with the connection's
 * `quoteTableName()` and `quoteColumnName()`.
 *
 * | `TimeFormat` | The column holds | The bound cutoff |
 * |---|---|---|
 * | `unix` (default) | integer seconds | `1767225600` |
 * | `unix_ms` | integer milliseconds | `1767225600000` |
 * | `datetime` | a UTC date and time string | `2026-01-01 00:00:00`: UTC, a space separator, no zone |
 *
 * A `datetime` column compares as a string (sqlite) or in the database session's time zone
 * (MySQL `TIMESTAMP`, PostgreSQL `timestamptz`). Store UTC with a space separator, or set the
 * session time zone to UTC; an ISO 8601 `T` separator mis-compares on sqlite.
 *
 * `BatchSize` 0 (the default) deletes in one statement. A positive `BatchSize` deletes at most that
 * many rows per statement, by `KeyColumn` (default `id`, a unique column), until a statement
 * deletes fewer; each statement holds its locks briefly. The statement is portable across sqlite,
 * MySQL and PostgreSQL:
 *
 * ```sql
 * DELETE FROM t WHERE id IN (SELECT id FROM (SELECT id FROM t WHERE created_at < ? LIMIT 1000) privacy_batch)
 * ```
 *
 * A zero `Period` makes the cutoff the current time and deletes every row of a table keyed by a
 * creation time, so the purger refuses a cutoff at or after the current time unless
 * `AllowZeroPeriod` is true.
 *
 * ```xml
 * <purger class="belisoful\Privacy\Retention\TDbRetentionPurger" ConnectionID="db" BatchSize="1000"
 *     Table="newsletter_bounces" TimeColumn="bounced_at" TimeFormat="datetime" Condition="resolved = 1" />
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 */
class TDbRetentionPurger extends TComponent implements IRetentionPurger
{
	use TApplicationClockAwareTrait;
	use TPrivacyDbConnectionTrait;

	/** The pattern of a table or column name: letters, digits and underscores, optionally schema-qualified. */
	public const IDENTIFIER_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/';

	/** The time formats. */
	public const TIME_FORMATS = ['unix', 'unix_ms', 'datetime'];

	/** @var string the table */
	private string $_table = '';

	/** @var string the time column */
	private string $_timeColumn = '';

	/** @var string the time format */
	private string $_timeFormat = 'unix';

	/** @var string the extra condition */
	private string $_condition = '';

	/** @var int the rows per statement; 0 for one statement */
	private int $_batchSize = 0;

	/** @var string the unique column batches select by */
	private string $_keyColumn = 'id';

	/** @var bool whether a cutoff at or after the current time is accepted */
	private bool $_allowZeroPeriod = false;

	/**
	 * Deletes the rows before the cutoff, in batches when `BatchSize` is positive.
	 * @param int $before a Unix time
	 * @throws TConfigurationException when `Table` or `TimeColumn` is not set, or the cutoff is not in the past and `AllowZeroPeriod` is false
	 * @return int the rows deleted
	 */
	public function purge(int $before): int
	{
		if ($this->_table === '' || $this->_timeColumn === '') {
			throw new TConfigurationException('privacy_purger_table_missing', static::class);
		}
		if (!$this->_allowZeroPeriod && $before >= $this->getClock()->time()) {
			throw new TConfigurationException('privacy_purger_zero_period', $this->_table);
		}
		$connection = $this->getDbConnection();
		$table = $connection->quoteTableName($this->_table);
		$where = $connection->quoteColumnName($this->_timeColumn) . ' < :before'
			. ($this->_condition === '' ? '' : ' AND (' . $this->_condition . ')');
		if ($this->_batchSize === 0) {
			return $this->execute($connection, 'DELETE FROM ' . $table . ' WHERE ' . $where, $before);
		}
		$key = $connection->quoteColumnName($this->_keyColumn);
		$sql = 'DELETE FROM ' . $table . ' WHERE ' . $key . ' IN (SELECT ' . $key . ' FROM (SELECT ' . $key
			. ' FROM ' . $table . ' WHERE ' . $where . ' LIMIT ' . $this->_batchSize . ') privacy_batch)';
		$total = 0;
		do {
			$total += $deleted = $this->execute($connection, $sql, $before);
		} while ($deleted >= $this->_batchSize);
		return $total;
	}

	/**
	 * Runs one delete statement with the cutoff bound.
	 * @param TDbConnection $connection the connection
	 * @param string $sql the statement
	 * @param int $before a Unix time
	 * @return int the rows deleted
	 */
	protected function execute(TDbConnection $connection, string $sql, int $before): int
	{
		$command = $connection->createCommand($sql);
		$command->bindValue(':before', match ($this->_timeFormat) {
			'datetime' => \gmdate('Y-m-d H:i:s', $before),
			'unix_ms' => $before * 1000,
			default => $before,
		});
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
	 * @return string `unix`, `unix_ms` or `datetime`
	 */
	public function getTimeFormat(): string
	{
		return $this->_timeFormat;
	}

	/**
	 * @param mixed $value `unix` (integer seconds), `unix_ms` (integer milliseconds) or `datetime` (`Y-m-d H:i:s`, UTC)
	 * @throws TInvalidDataValueException when the value is none of them
	 * @return static the purger
	 */
	public function setTimeFormat($value): static
	{
		$value = \strtolower(\trim(TPropertyValue::ensureString($value)));
		if (!\in_array($value, self::TIME_FORMATS, true)) {
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
	 * @return int the rows deleted per statement; 0 for one statement
	 */
	public function getBatchSize(): int
	{
		return $this->_batchSize;
	}

	/**
	 * @param mixed $value the rows deleted per statement; 0 for one statement
	 * @throws TInvalidDataValueException when the value is negative
	 * @return static the purger
	 */
	public function setBatchSize($value): static
	{
		$value = TPropertyValue::ensureInteger($value);
		if ($value < 0) {
			throw new TInvalidDataValueException('privacy_purger_batch_size_invalid', $value);
		}
		$this->_batchSize = $value;
		return $this;
	}

	/**
	 * @return string the unique column a batch selects rows by
	 */
	public function getKeyColumn(): string
	{
		return $this->_keyColumn;
	}

	/**
	 * @param mixed $value the unique column a batch selects rows by
	 * @throws TInvalidDataValueException when the value is not a column name
	 * @return static the purger
	 */
	public function setKeyColumn($value): static
	{
		$this->_keyColumn = self::ensureIdentifier($value);
		return $this;
	}

	/**
	 * @return bool whether a cutoff at or after the current time (a zero `Period`) is accepted
	 */
	public function getAllowZeroPeriod(): bool
	{
		return $this->_allowZeroPeriod;
	}

	/**
	 * @param mixed $value whether a cutoff at or after the current time (a zero `Period`) is accepted
	 * @return static the purger
	 */
	public function setAllowZeroPeriod($value): static
	{
		$this->_allowZeroPeriod = TPropertyValue::ensureBoolean($value);
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
