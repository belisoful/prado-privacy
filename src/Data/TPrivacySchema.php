<?php

/**
 * TPrivacySchema class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Data;

use Prado\Data\TDbConnection;
use Prado\Exceptions\TConfigurationException;

/**
 * TPrivacySchema class.
 *
 * TPrivacySchema holds the DDL of the package's tables for sqlite, MySQL (and MariaDB) and
 * PostgreSQL, and creates a table on first use. `config/schema/<driver>.sql` holds the same
 * statements for a database administrator who creates the tables by hand.
 *
 * | Table | Written by |
 * |---|---|
 * | `privacy_consent_log` | `TDbConsentLog` |
 * | `privacy_consent_state` | `TDbConsentStore` |
 * | `privacy_request` | `TDbPrivacyRequestLog` |
 *
 * Times are integer Unix seconds. Identifiers of people are HMAC-SHA256 hex digests.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 */
class TPrivacySchema
{
	/** The consent log table. */
	public const CONSENT_LOG = 'privacy_consent_log';

	/** The consent state table. */
	public const CONSENT_STATE = 'privacy_consent_state';

	/** The data subject request table. */
	public const REQUEST = 'privacy_request';

	/** The drivers the schema supports. */
	public const DRIVERS = ['sqlite', 'mysql', 'pgsql'];

	/** The auto-increment primary key column type per driver. */
	public const PRIMARY_KEYS = [
		'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
		'mysql' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
		'pgsql' => 'BIGSERIAL PRIMARY KEY',
	];

	/** @var array<string, true> the tables created this process, keyed by connection id and table */
	private static array $_created = [];

	/**
	 * @return string[] the tables the schema defines
	 */
	public static function getTables(): array
	{
		return [self::CONSENT_LOG, self::CONSENT_STATE, self::REQUEST];
	}

	/**
	 * Returns the statements that create a table and its indexes.
	 * @param string $table the table
	 * @param string $driver `sqlite`, `mysql` or `pgsql`
	 * @throws TConfigurationException when the driver or the table is unknown
	 * @return string[] the statements, each safe to run when the table already exists
	 */
	public static function getCreateStatements(string $table, string $driver): array
	{
		$pk = self::PRIMARY_KEYS[$driver] ?? null;
		if ($pk === null) {
			throw new TConfigurationException('privacy_db_driver_unsupported', $driver, \implode(', ', self::DRIVERS));
		}
		$int = $driver === 'sqlite' ? 'INTEGER' : 'BIGINT';
		$suffix = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
		switch ($table) {
			case self::CONSENT_LOG:
				$create = 'CREATE TABLE IF NOT EXISTS ' . $table . ' ('
					. 'id ' . $pk . ', '
					. 'consent_id VARCHAR(32) NOT NULL, '
					. 'created_at ' . $int . ' NOT NULL, '
					. 'version VARCHAR(64) NOT NULL, '
					. 'categories TEXT NOT NULL, '
					. 'source VARCHAR(16) NOT NULL, '
					. 'region VARCHAR(8) NULL, '
					. 'gpc SMALLINT NOT NULL DEFAULT 0, '
					. 'ip_hash CHAR(64) NULL, '
					. 'subject_hash CHAR(64) NULL, '
					. 'user_agent_hash CHAR(64) NULL'
					. ($driver === 'mysql' ? ', INDEX idx_privacy_consent_log_consent (consent_id), INDEX idx_privacy_consent_log_created (created_at), INDEX idx_privacy_consent_log_subject (subject_hash)' : '')
					. ')' . $suffix;
				$indexes = ['consent_id' => 'consent', 'created_at' => 'created', 'subject_hash' => 'subject'];
				break;
			case self::CONSENT_STATE:
				$create = 'CREATE TABLE IF NOT EXISTS ' . $table . ' ('
					. 'consent_id VARCHAR(32) NOT NULL PRIMARY KEY, '
					. 'subject_hash CHAR(64) NULL, '
					. 'categories TEXT NOT NULL, '
					. 'version VARCHAR(64) NOT NULL, '
					. 'source VARCHAR(16) NOT NULL, '
					. 'updated_at ' . $int . ' NOT NULL, '
					. 'expires_at ' . $int . ' NOT NULL'
					. ($driver === 'mysql' ? ', INDEX idx_privacy_consent_state_subject (subject_hash), INDEX idx_privacy_consent_state_expires (expires_at)' : '')
					. ')' . $suffix;
				$indexes = ['subject_hash' => 'subject', 'expires_at' => 'expires'];
				break;
			case self::REQUEST:
				$create = 'CREATE TABLE IF NOT EXISTS ' . $table . ' ('
					. 'id ' . $pk . ', '
					. 'type VARCHAR(16) NOT NULL, '
					. 'subject_hash CHAR(64) NOT NULL, '
					. 'requested_by VARCHAR(255) NULL, '
					. 'requested_at ' . $int . ' NOT NULL, '
					. 'due_at ' . $int . ' NOT NULL, '
					. 'completed_at ' . $int . ' NULL, '
					. 'status VARCHAR(16) NOT NULL, '
					. 'outcome TEXT NULL'
					. ($driver === 'mysql' ? ', INDEX idx_privacy_request_subject (subject_hash), INDEX idx_privacy_request_status (status), INDEX idx_privacy_request_requested (requested_at)' : '')
					. ')' . $suffix;
				$indexes = ['subject_hash' => 'subject', 'status' => 'status', 'requested_at' => 'requested'];
				break;
			default:
				throw new TConfigurationException('privacy_db_table_unknown', $table);
		}
		$statements = [$create];
		if ($driver !== 'mysql') {
			foreach ($indexes as $column => $name) {
				$statements[] = 'CREATE INDEX IF NOT EXISTS idx_' . $table . '_' . $name . ' ON ' . $table . ' (' . $column . ')';
			}
		}
		return $statements;
	}

	/**
	 * Creates a table on a connection unless this process already did.
	 * @param TDbConnection $connection the connection, made active
	 * @param string $table the table
	 * @throws TConfigurationException when the connection's driver or the table is unknown
	 */
	public static function ensureTable(TDbConnection $connection, string $table): void
	{
		$key = \spl_object_id($connection) . ':' . $table;
		if (isset(self::$_created[$key])) {
			return;
		}
		$connection->setActive(true);
		foreach (self::getCreateStatements($table, (string) $connection->getDriverName()) as $sql) {
			$connection->createCommand($sql)->execute();
		}
		self::$_created[$key] = true;
	}

	/**
	 * @param string $driver `sqlite`, `mysql` or `pgsql`
	 * @return string the whole schema for a driver, one statement per line, as `config/schema/<driver>.sql` holds it
	 */
	public static function getScript(string $driver): string
	{
		$lines = ['-- prado-privacy schema for ' . $driver . '; generated from TPrivacySchema.'];
		foreach (self::getTables() as $table) {
			foreach (self::getCreateStatements($table, $driver) as $sql) {
				$lines[] = $sql . ';';
			}
		}
		return \implode("\n", $lines) . "\n";
	}
}
