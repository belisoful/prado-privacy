<?php

namespace belisoful\Privacy\Test\Unit;

use belisoful\Privacy\Data\TPrivacySchema;
use Prado\Data\TDbConnection;

/**
 * One in-memory sqlite connection for the suite. TActiveRecordGateway caches table metadata by
 * connection string, so every "sqlite::memory:" connection would share one cache entry; the suite
 * shares the connection instead and empties the tables before each test.
 */
class TestDb
{
	private static ?TDbConnection $_connection = null;

	public static function connection(): TDbConnection
	{
		if (self::$_connection === null) {
			self::$_connection = new TDbConnection('sqlite::memory:');
			self::$_connection->setActive(true);
			foreach (TPrivacySchema::getTables() as $table) {
				TPrivacySchema::ensureTable(self::$_connection, $table);
			}
		}
		return self::$_connection;
	}

	public static function reset(): TDbConnection
	{
		$connection = self::connection();
		foreach (TPrivacySchema::getTables() as $table) {
			$connection->createCommand('DELETE FROM ' . $table)->execute();
		}
		return $connection;
	}

	public static function count(string $table): int
	{
		return (int) self::connection()->createCommand('SELECT COUNT(*) FROM ' . $table)->queryScalar();
	}
}
