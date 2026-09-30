<?php

namespace belisoful\Privacy\Test\Unit\Consent\Region;

use belisoful\Privacy\Consent\Region\TIpLocation;
use PHPUnit\Framework\TestCase;

class TIpLocationTest extends TestCase
{
	public function testAccessorsAndRegion()
	{
		$location = new TIpLocation('US', 'CA', 'mmdb');
		$this->assertSame('US', $location->getCountry());
		$this->assertSame('CA', $location->getSubdivision());
		$this->assertSame('mmdb', $location->getSource());
		$this->assertSame('US-CA', $location->getRegion());
		$this->assertSame('DE', (new TIpLocation('DE'))->getRegion());
	}

	public function testFromDataByPaths()
	{
		$data = ['location' => ['country' => ['code' => 'us'], 'region' => ['code' => 'US-CA']], 'list' => [['iso_code' => 'TX']]];
		$this->assertSame('US-CA', TIpLocation::fromData($data, 'location.country.code', 'location.region.code', 'x')->getRegion());
		$this->assertSame('US-TX', TIpLocation::fromData($data, 'location.country.code', 'list.0.iso_code', 'x')->getRegion());
		$this->assertSame('US', TIpLocation::fromData($data, 'location.country.code', '', 'x')->getRegion());
		$this->assertSame('US', TIpLocation::fromData($data, 'location.country.code', 'missing.path', 'x')->getRegion());
		$this->assertNull(TIpLocation::fromData($data, 'location.country', '', 'x'), 'a structure is not a code');
		$this->assertNull(TIpLocation::fromData(['country' => 'Germany'], 'country', '', 'x'));
		$this->assertNull(TIpLocation::fromData(null, 'country', '', 'x'));
	}

	public function testSubdivisionNormalization()
	{
		$this->assertSame('CA', TIpLocation::normalizeSubdivision('US', 'ca'));
		$this->assertSame('CA', TIpLocation::normalizeSubdivision('US', 'US-CA'));
		$this->assertSame('CA', TIpLocation::normalizeSubdivision('US', 'California'));
		$this->assertSame('DC', TIpLocation::normalizeSubdivision('US', 'District of Columbia'));
		$this->assertNull(TIpLocation::normalizeSubdivision('US', 'Atlantis'));
		$this->assertNull(TIpLocation::normalizeSubdivision('DE', 'Bayern'));
		$this->assertSame('BY', TIpLocation::normalizeSubdivision('DE', 'DE-BY'));
		$this->assertNull(TIpLocation::normalizeSubdivision('US', ''));
	}
}
