<?php

namespace belisoful\Privacy\Test\Unit\Consent\Region;

use belisoful\Privacy\Consent\Region\THeaderRegionResolver;
use belisoful\Privacy\Consent\Region\TScopeAllRegionResolver;
use belisoful\Privacy\Test\Unit\FakeRequest;
use PHPUnit\Framework\TestCase;

class THeaderRegionResolverTest extends TestCase
{
	private function request(array $headers): FakeRequest
	{
		$request = new FakeRequest();
		$request->headers = $headers;
		return $request;
	}

	public function testCloudflareAndCloudFront()
	{
		$resolver = new THeaderRegionResolver();
		$this->assertSame('DE', $resolver->resolveRegion($this->request(['CF-IPCountry' => 'de'])));
		$this->assertSame('US-CA', $resolver->resolveRegion($this->request(['CF-IPCountry' => 'US', 'cf-region-code' => 'CA'])));
		$this->assertSame('US-TX', $resolver->resolveRegion($this->request(['CloudFront-Viewer-Country' => 'US', 'CloudFront-Viewer-Country-Region' => 'tx'])));
	}

	public function testUnknownAndUnusableValues()
	{
		$resolver = new THeaderRegionResolver();
		$this->assertNull($resolver->resolveRegion($this->request([])));
		$this->assertNull($resolver->resolveRegion($this->request(['CF-IPCountry' => 'XX'])));
		$this->assertNull($resolver->resolveRegion($this->request(['CF-IPCountry' => 'T1'])));
		$this->assertSame('FR', $resolver->resolveRegion($this->request(['CF-IPCountry' => 'France', 'CloudFront-Viewer-Country' => 'FR', 'cf-region-code' => 'ÎLE'])));
	}

	public function testConfiguredHeaders()
	{
		$resolver = (new THeaderRegionResolver())->setCountryHeaders(' X-Country-Code , ')->setSubdivisionHeaders('');
		$this->assertSame(['X-Country-Code'], $resolver->getCountryHeaders());
		$this->assertSame([], $resolver->getSubdivisionHeaders());
		$this->assertSame('US', $resolver->resolveRegion($this->request(['X-Country-Code' => 'US', 'cf-region-code' => 'CA'])));
		$resolver->setSubdivisionHeaders(['X-Region']);
		$this->assertSame('US-NY', $resolver->resolveRegion($this->request(['X-Country-Code' => 'US', 'X-Region' => 'NY'])));
	}

	public function testScopeAllPlacesNobody()
	{
		$this->assertNull((new TScopeAllRegionResolver())->resolveRegion($this->request(['CF-IPCountry' => 'DE'])));
	}
}
