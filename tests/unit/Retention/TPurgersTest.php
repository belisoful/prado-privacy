<?php

namespace belisoful\Privacy\Test\Unit\Retention;

use belisoful\Privacy\Retention\TDbRetentionPurger;
use belisoful\Privacy\Retention\TMethodRetentionPurger;
use belisoful\Privacy\Test\Unit\PrivacyTestCase;
use belisoful\Privacy\Test\Unit\TestDb;
use Prado\Data\TDbConnection;
use Prado\Exceptions\TConfigurationException;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\TModule;

class TPurgersTest extends PrivacyTestCase
{
	private function orders(): TDbConnection
	{
		$connection = TestDb::connection();
		$connection->createCommand('DROP TABLE IF EXISTS test_orders')->execute();
		$connection->createCommand('CREATE TABLE test_orders (id INTEGER PRIMARY KEY, created_at INTEGER, placed TEXT, paid INTEGER)')->execute();
		foreach ([[1, 100, '2020-01-01 00:00:00', 1], [2, 200, '2021-01-01 00:00:00', 0], [3, 300, '2022-01-01 00:00:00', 1]] as $row) {
			$connection->createCommand('INSERT INTO test_orders VALUES (' . \implode(',', \array_map(fn ($v) => \is_int($v) ? $v : "'" . $v . "'", $row)) . ')')->execute();
		}
		return $connection;
	}

	public function testDbPurgerByUnixTime()
	{
		$purger = (new TDbRetentionPurger())->setDbConnection($this->orders())->setTable('test_orders')->setTimeColumn('created_at');
		$this->assertSame('test_orders', $purger->getTable());
		$this->assertSame('created_at', $purger->getTimeColumn());
		$this->assertSame('unix', $purger->getTimeFormat());
		$this->assertSame(2, $purger->purge(300));
		$this->assertSame(1, TestDb::count('test_orders'));
	}

	public function testDbPurgerByDatetimeWithACondition()
	{
		$purger = (new TDbRetentionPurger())->setDbConnection($this->orders())
			->setTable('main.test_orders')->setTimeColumn('placed')->setTimeFormat('DATETIME')->setCondition(' paid = 1 ');
		$this->assertSame('datetime', $purger->getTimeFormat());
		$this->assertSame('paid = 1', $purger->getCondition());
		$this->assertSame(1, $purger->purge(\strtotime('2021-06-01 UTC')), 'the unpaid 2021 order stays');
	}

	public function testDbPurgerValidation()
	{
		$purger = new TDbRetentionPurger();
		foreach ([fn () => $purger->setTable('orders; DROP TABLE x'), fn () => $purger->setTimeColumn('a b'), fn () => $purger->setTimeFormat('iso')] as $call) {
			try {
				$call();
				$this->fail('refused');
			} catch (TInvalidDataValueException $e) {
				$this->assertStringStartsWith('privacy_purger_', $e->getErrorCode());
			}
		}
		$this->expectException(TConfigurationException::class);
		$purger->purge(0);
	}

	public function testMethodPurger()
	{
		$module = new class () extends TModule {
			public array $calls = [];

			public function purgeBefore(int $before): int
			{
				$this->calls[] = $before;
				return 4;
			}
		};
		$this->addModule('vendor/uploads', $module);
		$purger = (new TMethodRetentionPurger())->setMethod(' vendor/uploads->purgeBefore ');
		$this->assertSame('vendor/uploads->purgeBefore', $purger->getMethod());
		$this->assertSame(4, $purger->purge(123));
		$this->assertSame([123], $module->calls);
		foreach (['vendor/uploads->missing', 'nomodule->purgeBefore'] as $method) {
			try {
				(new TMethodRetentionPurger())->setMethod($method)->purge(1);
				$this->fail($method);
			} catch (TConfigurationException $e) {
				$this->assertSame('privacy_purger_method_missing', $e->getErrorCode());
			}
		}
		$this->expectException(TInvalidDataValueException::class);
		(new TMethodRetentionPurger())->setMethod('just-a-module');
	}
}
