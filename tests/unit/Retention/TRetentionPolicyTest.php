<?php

namespace belisoful\Privacy\Test\Unit\Retention;

use belisoful\Privacy\Retention\IRetentionPurger;
use belisoful\Privacy\Retention\TDbRetentionPurger;
use belisoful\Privacy\Retention\TMethodRetentionPurger;
use belisoful\Privacy\Retention\TRetentionPolicy;
use belisoful\Privacy\Test\Unit\PrivacyTestCase;
use belisoful\Privacy\Test\Unit\TestDb;
use Prado\Exceptions\TConfigurationException;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\TModule;

class TRetentionPolicyTest extends PrivacyTestCase
{
	public function testPeriodsAndCutoffs()
	{
		$now = \strtotime('2026-09-29 12:00:00 UTC');
		$policy = new TRetentionPolicy('orders', 'p3y', null, 'tax law');
		$this->assertSame('orders', $policy->getName());
		$this->assertSame('P3Y', $policy->getPeriod());
		$this->assertSame('tax law', $policy->getDescription());
		$this->assertSame(\strtotime('2023-09-29 12:00:00 UTC'), $policy->getCutoff($now));
		$this->assertSame(\strtotime('2026-03-29 12:00:00 UTC'), $policy->setPeriod('P6M')->getCutoff($now));
		$this->assertSame($now - 43200, $policy->setPeriod('PT12H')->getCutoff($now));
		$this->assertSame($now, $policy->setPeriod('P0D')->getCutoff($now));
		$policy->setName(' logs ')->setDescription(' kept ');
		$this->assertSame('logs', $policy->getName());
		$this->assertSame('kept', $policy->getDescription());
	}

	public function testAnInvalidPeriod()
	{
		$this->expectException(TInvalidDataValueException::class);
		new TRetentionPolicy('x', 'three years');
	}

	public function testAMissingPeriod()
	{
		$this->expectException(TConfigurationException::class);
		(new TRetentionPolicy('x'))->getCutoff(0);
	}

	public function testPurgerResolution()
	{
		$policy = new TRetentionPolicy('x', 'P1D');
		try {
			$policy->getPurger();
			$this->fail('missing');
		} catch (TConfigurationException $e) {
			$this->assertSame('privacy_retention_purger_missing', $e->getErrorCode());
		}
		$policy->setPurger(['class' => TMethodRetentionPurger::class, 'Method' => 'a->b']);
		$this->assertSame('a->b', $policy->getPurger()->getMethod());
		$purger = new TDbRetentionPurger();
		$this->assertSame($purger, $policy->setPurger($purger)->getPurger());
		$policy->setPurger('');
		$this->expectException(TConfigurationException::class);
		$policy->getPurger();
	}
}
