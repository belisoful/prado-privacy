<?php

namespace belisoful\Privacy\Test\Unit\Consent\Region;

use belisoful\Privacy\Consent\Region\TRegionGroups;
use PHPUnit\Framework\TestCase;
use Prado\Exceptions\TInvalidDataValueException;

class TRegionGroupsTest extends TestCase
{
	public function testGroups()
	{
		$this->assertSame(['EU', 'EEA', 'UK', 'CH', 'US-GPC'], \array_slice(TRegionGroups::getGroupNames(), 0, 5));
		$this->assertCount(27, TRegionGroups::expand('EU'));
		$eea = TRegionGroups::expand('eea');
		$this->assertCount(30, $eea);
		$this->assertContains('NO', $eea);
		$this->assertSame(['GB'], TRegionGroups::expand('UK'));
		$this->assertContains('US-CA', TRegionGroups::expand('US-GPC'));
	}

	public function testExpandMixesGroupsAndCodesWithoutDuplicates()
	{
		$this->assertSame(['GB', 'CH', 'US-CA'], TRegionGroups::expand(' UK, ch ,, us-ca, GB'));
		$this->assertSame(['DE', 'US'], TRegionGroups::expand(['de', 'US', '']));
	}

	public function testExpandRefusesAnUnknownEntry()
	{
		$this->expectException(TInvalidDataValueException::class);
		TRegionGroups::expand('EU, Europe');
	}

	public function testDefineGroup()
	{
		TRegionGroups::defineGroup(' latam ', ['BR', '@UK']);
		$this->assertSame(['BR', 'GB'], TRegionGroups::expand('LATAM'));
	}

	public function testMatches()
	{
		$codes = TRegionGroups::expand('EU, US-CA, CA');
		$this->assertTrue(TRegionGroups::matches('de', $codes));
		$this->assertTrue(TRegionGroups::matches('US-CA', $codes));
		$this->assertFalse(TRegionGroups::matches('US-NY', $codes));
		$this->assertTrue(TRegionGroups::matches('CA-QC', $codes), 'a subdivision matches its country');
		$this->assertFalse(TRegionGroups::matches(null, $codes));
	}

	public function testNormalize()
	{
		$this->assertSame('US-CA', TRegionGroups::normalize(' us-ca '));
		$this->assertSame('DE', TRegionGroups::normalize('de'));
		$this->assertNull(TRegionGroups::normalize('Germany'));
		$this->assertNull(TRegionGroups::normalize(null));
	}
}
