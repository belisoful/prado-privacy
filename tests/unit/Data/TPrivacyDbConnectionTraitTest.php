<?php

namespace belisoful\Privacy\Test\Unit\Data;

use belisoful\Privacy\Consent\TDbConsentLog;
use belisoful\Privacy\Test\Unit\PrivacyTestCase;
use belisoful\Privacy\Test\Unit\TestDb;
use Prado\Data\TDbConnection;
use Prado\Exceptions\TConfigurationException;
use Prado\Security\TAuthManager;
use Prado\TModule;

class TPrivacyDbConnectionTraitTest extends PrivacyTestCase
{
	public function testDefaultIsTheRuntimeSqliteDatabase()
	{
		$log = new TDbConsentLog();
		$this->assertSame('', $log->getConnectionID());
		$this->assertTrue($log->getAutoCreateTables());
		$connection = $log->getDbConnection();
		$this->assertSame('sqlite:' . $this->app->getRuntimePath() . DIRECTORY_SEPARATOR . 'privacy.db', $connection->getConnectionString());
		$this->assertTrue($connection->getActive());
		$this->assertSame($connection, $log->getDbConnection());
		$connection->setActive(false);
		@\unlink($this->app->getRuntimePath() . DIRECTORY_SEPARATOR . 'privacy.db');
	}

	public function testConnectionIdNamesAModule()
	{
		$this->addModule('privacy-db', new class () extends TModule {
			public function getDbConnection(): TDbConnection
			{
				return TestDb::connection();
			}
		});
		$log = (new TDbConsentLog())->setConnectionID(' privacy-db ');
		$this->assertSame('privacy-db', $log->getConnectionID());
		$this->assertSame(TestDb::connection(), $log->getDbConnection());
	}

	public function testConnectionIdOfAModuleWithoutAConnection()
	{
		$this->addModule('auth-only', new TAuthManager());
		$this->expectException(TConfigurationException::class);
		(new TDbConsentLog())->setConnectionID('auth-only')->getDbConnection();
	}

	public function testConnectionIdOfAMissingModule()
	{
		$this->expectException(TConfigurationException::class);
		(new TDbConsentLog())->setConnectionID('no-such-module')->getDbConnection();
	}

	public function testWithoutSqliteTheDefaultIsRefused()
	{
		$log = new class () extends TDbConsentLog {
			protected function hasSqliteDriver(): bool
			{
				return false;
			}
		};
		$this->expectException(TConfigurationException::class);
		$log->getDbConnection();
	}

	public function testInjectedConnectionAndAutoCreateTables()
	{
		$connection = new TDbConnection('sqlite::memory:');
		$log = (new TDbConsentLog())->setDbConnection($connection)->setAutoCreateTables(false);
		$this->assertFalse($log->getAutoCreateTables());
		$this->assertSame($connection, $log->getDbConnection());
		$log->setDbConnection(null);
		$this->assertNotSame($connection, $log->getDbConnection());
	}
}
