<?php

namespace belisoful\Privacy\Test\Unit\Consent\Region;

use belisoful\Privacy\Consent\Region\TChainRegionResolver;
use belisoful\Privacy\Consent\Region\THeaderRegionResolver;
use belisoful\Privacy\Consent\Region\TScopeAllRegionResolver;
use belisoful\Privacy\Test\Unit\FakeRequest;
use belisoful\Privacy\Test\Unit\PrivacyTestCase;

class TChainRegionResolverTest extends PrivacyTestCase
{
	public function testTheFirstAnswerWins()
	{
		$request = new FakeRequest();
		$request->headers = ['X-Country-Code' => 'CH', 'CF-IPCountry' => 'DE'];
		$chain = (new TChainRegionResolver())->setResolvers([
			new TScopeAllRegionResolver(),
			['class' => THeaderRegionResolver::class, 'CountryHeaders' => 'X-Country-Code'],
		])->addResolver(new THeaderRegionResolver());
		$this->assertSame('CH', $chain->resolveRegion($request));
		$this->assertCount(3, $chain->getResolvers());
		$this->assertInstanceOf(THeaderRegionResolver::class, $chain->getResolvers()[1]);
	}

	public function testModuleIdsAndNoAnswer()
	{
		$this->addModule('geo-a', new class () extends \Prado\TModule implements \belisoful\Privacy\Consent\Region\IRegionResolver {
			public function resolveRegion(\Prado\Web\THttpRequest $request): ?string
			{
				return null;
			}
		});
		$chain = (new TChainRegionResolver())->setResolvers(' geo-a , ');
		$this->assertCount(1, $chain->getResolvers());
		$this->assertNull($chain->resolveRegion(new FakeRequest()));
	}

	public function testACountryWithSubdivisionsAsksTheNextResolvers()
	{
		$request = new FakeRequest();
		$chain = fn (...$regions) => (new TChainRegionResolver())->setResolvers(\array_map(fn ($region) => new FixedRegionResolver($region), $regions));
		$this->assertSame('US-CA', $chain('US', null, 'DE', 'US', 'US-CA', 'US-TX')->resolveRegion($request), 'the first subdivision of the country');
		$this->assertSame('US', $chain('US', 'DE', null)->resolveRegion($request), 'the country when no resolver knows the state');
		$this->assertSame('DE', $chain('DE', 'US-CA')->resolveRegion($request), 'a country no group divides is final');
		$this->assertSame('US-NY', $chain(null, 'US-NY', 'US-CA')->resolveRegion($request));
	}
}

/**
 * Answers one fixed region.
 */
class FixedRegionResolver extends \Prado\TComponent implements \belisoful\Privacy\Consent\Region\IRegionResolver
{
	public function __construct(private ?string $_region)
	{
		parent::__construct();
	}

	public function resolveRegion(\Prado\Web\THttpRequest $request): ?string
	{
		return $this->_region;
	}
}
