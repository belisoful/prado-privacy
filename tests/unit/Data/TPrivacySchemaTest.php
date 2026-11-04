<?php

namespace belisoful\Privacy\Test\Unit\Data;

use belisoful\Privacy\Data\TPrivacySchema;
use PHPUnit\Framework\TestCase;
use Prado\Data\TDbConnection;
use Prado\Exceptions\TConfigurationException;

class TPrivacySchemaTest extends TestCase
{
	public function testTheShippedScriptsMatchTheSchema()
	{
		foreach (TPrivacySchema::DRIVERS as $driver) {
			$this->assertStringEqualsFile(\dirname(__DIR__, 3) . '/config/schema/' . $driver . '.sql', TPrivacySchema::getScript($driver), $driver);
		}
	}

	public function testStatementsPerDriver()
	{
		$sqlite = TPrivacySchema::getCreateStatements(TPrivacySchema::CONSENT_LOG, 'sqlite');
		$this->assertCount(4, $sqlite);
		$this->assertStringContainsString('INTEGER PRIMARY KEY AUTOINCREMENT', $sqlite[0]);
		$this->assertStringContainsString('CREATE INDEX IF NOT EXISTS idx_privacy_consent_log_consent', $sqlite[1]);
		$mysql = TPrivacySchema::getCreateStatements(TPrivacySchema::CONSENT_STATE, 'mysql');
		$this->assertCount(1, $mysql);
		$this->assertStringContainsString('INDEX idx_privacy_consent_state_expires (expires_at)', $mysql[0]);
		$this->assertStringContainsString('ENGINE=InnoDB', $mysql[0]);
		$pgsql = TPrivacySchema::getCreateStatements(TPrivacySchema::CONSENT_LOG, 'pgsql');
		$this->assertStringContainsString('BIGSERIAL PRIMARY KEY', $pgsql[0]);
		$this->assertStringContainsString('created_at BIGINT', $pgsql[0]);
	}

	public function testUnknownDriver()
	{
		$this->expectException(TConfigurationException::class);
		TPrivacySchema::getCreateStatements(TPrivacySchema::CONSENT_LOG, 'oci');
	}

	public function testUnknownTable()
	{
		$this->expectException(TConfigurationException::class);
		TPrivacySchema::getCreateStatements('nope', 'sqlite');
	}

	public function testEnsureTableCreatesOncePerConnection()
	{
		$connection = new TDbConnection('sqlite::memory:');
		TPrivacySchema::ensureTable($connection, TPrivacySchema::CONSENT_STATE);
		$this->assertSame(0, (int) $connection->createCommand('SELECT COUNT(*) FROM privacy_consent_state')->queryScalar());
		$connection->createCommand('DROP TABLE privacy_consent_state')->execute();
		TPrivacySchema::ensureTable($connection, TPrivacySchema::CONSENT_STATE);
		$this->assertFalse((bool) $connection->createCommand("SELECT COUNT(*) FROM sqlite_master WHERE name = 'privacy_consent_state'")->queryScalar(), 'the second call is a no-op');
	}

	public function testEnsureTableRunsNoDdlForAnExistingTable()
	{
		$connection = new class ('sqlite::memory:') extends TDbConnection {
			public array $statements = [];

			public function createCommand($sql)
			{
				$this->statements[] = $sql;
				return parent::createCommand($sql);
			}
		};
		$connection->setActive(true);
		$connection->getPdoInstance()->exec('CREATE TABLE privacy_request (id INTEGER PRIMARY KEY)');
		TPrivacySchema::ensureTable($connection, TPrivacySchema::REQUEST);
		TPrivacySchema::ensureTable($connection, TPrivacySchema::CONSENT_LOG);
		TPrivacySchema::ensureTable($connection, TPrivacySchema::REQUEST);
		TPrivacySchema::ensureTable($connection, TPrivacySchema::CONSENT_LOG);
		$ddl = \array_values(\array_filter($connection->statements, fn ($sql) => \str_starts_with($sql, 'CREATE')));
		$this->assertSame(TPrivacySchema::getCreateStatements(TPrivacySchema::CONSENT_LOG, 'sqlite'), $ddl, 'only the missing table is created');
		$this->assertCount(1, \array_filter($connection->statements, fn ($sql) => \str_contains($sql, 'sqlite_master')), 'the table names are read once');
		$known = (new \ReflectionProperty(TPrivacySchema::class, '_tables'))->getValue();
		$this->assertTrue(isset($known[$connection]));
		$before = \count($known);
		unset($connection);
		\gc_collect_cycles();
		$this->assertLessThan($before, \count($known), 'a released connection leaves the cache');
	}
}
