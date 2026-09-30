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
}
