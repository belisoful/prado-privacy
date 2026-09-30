<?php

namespace belisoful\Privacy\Test\Unit\Consent\Region;

use belisoful\Privacy\Consent\Region\THttpIpLocator;
use belisoful\Privacy\Test\Unit\LocalHttpServer;
use belisoful\Privacy\Test\Unit\PrivacyTestCase;
use Prado\Exceptions\TInvalidDataValueException;

class THttpIpLocatorTest extends PrivacyTestCase
{
	/**
	 * A locator whose transport records the request and answers from a queue.
	 * @param array $answer
	 * @param int $status
	 */
	private function recording(array $answer, int $status = 200): THttpIpLocator
	{
		return new class ($answer, $status) extends THttpIpLocator {
			public array $calls = [];

			public function __construct(private array $answer, private int $status)
			{
				parent::__construct();
			}

			protected function transport(string $url, array $headers, float $timeout): array
			{
				$this->calls[] = [$url, $headers, $timeout];
				return [$this->status, \json_encode($this->answer)];
			}
		};
	}

	public static function services(): array
	{
		return [
			'maxmind' => ['maxmind', ['country' => ['iso_code' => 'US'], 'subdivisions' => [['iso_code' => 'CA']]], 'https://acct:k%2Fey@geoip.maxmind.com/geoip/v2.1/city/198.51.100.1', 'US-CA'],
			'ipinfo' => ['ipinfo', ['country' => 'US', 'region' => 'California'], 'https://ipinfo.io/198.51.100.1/json?token=k%2Fey', 'US-CA'],
			'ipinfo-lite' => ['ipinfo-lite', ['country_code' => 'DE'], 'https://api.ipinfo.io/lite/198.51.100.1?token=k%2Fey', 'DE'],
			'ipdata' => ['ipdata', ['country_code' => 'US', 'region_code' => 'TX'], 'https://api.ipdata.co/198.51.100.1?api-key=k%2Fey', 'US-TX'],
			'ipgeolocation' => ['ipgeolocation', ['country_code2' => 'US', 'state_code' => 'US-CO'], 'https://api.ipgeolocation.io/ipgeo?apiKey=k%2Fey&ip=198.51.100.1', 'US-CO'],
			'ipregistry' => ['ipregistry', ['location' => ['country' => ['code' => 'US'], 'region' => ['code' => 'US-OR']]], 'https://api.ipregistry.co/198.51.100.1?key=k%2Fey', 'US-OR'],
			'ipapi' => ['ipapi', ['country_code' => 'FR', 'region_code' => 'IDF'], 'https://ipapi.co/198.51.100.1/json/', 'FR-IDF'],
			'ipstack' => ['ipstack', ['country_code' => 'US', 'region_code' => 'NJ'], 'https://api.ipstack.com/198.51.100.1?access_key=k%2Fey', 'US-NJ'],
			'ip2location' => ['ip2location', ['country_code' => 'US', 'region_name' => 'New Hampshire'], 'https://api.ip2location.io/?key=k%2Fey&ip=198.51.100.1', 'US-NH'],
			'abstract' => ['abstract', ['country_code' => 'US', 'region_iso_code' => 'MT'], 'https://ipgeolocation.abstractapi.com/v1/?api_key=k%2Fey&ip_address=198.51.100.1', 'US-MT'],
			'bigdatacloud' => ['bigdatacloud', ['country' => ['isoAlpha2' => 'US'], 'location' => ['principalSubdivisionCode' => 'US-DE']], 'https://api-bdc.net/data/ip-geolocation?ip=198.51.100.1&key=k%2Fey', 'US-DE'],
			'dbip' => ['dbip', ['countryCode' => 'US', 'stateProvCode' => 'MN'], 'https://api.db-ip.com/v2/k%2Fey/198.51.100.1', 'US-MN'],
		];
	}

	/**
	 * @dataProvider services
	 * @param string $service
	 * @param array $answer
	 * @param string $url
	 * @param string $region
	 */
	public function testPresets(string $service, array $answer, string $url, string $region)
	{
		$locator = $this->recording($answer)->setService(' ' . \strtoupper($service))->setApiKey('k/ey')->setAccountId('acct');
		$this->assertSame($service, $locator->getService());
		$location = $locator->locate('198.51.100.1');
		$this->assertSame($region, $location->getRegion());
		$this->assertSame($service, $location->getSource());
		$this->assertSame($url, $locator->calls[0][0]);
		$this->assertSame(['Accept: application/json'], $locator->calls[0][1]);
		$this->assertSame(THttpIpLocator::DEFAULT_TIMEOUT, $locator->calls[0][2]);
	}

	public function testDbipWithoutAKeyUsesTheFreeEndpoint()
	{
		$locator = $this->recording(['countryCode' => 'DE'])->setService('dbip');
		$locator->locate('198.51.100.1');
		$this->assertSame('https://api.db-ip.com/v2/free/198.51.100.1', $locator->calls[0][0]);
	}

	public function testCustomUrlPathsAndAuthHeader()
	{
		$locator = $this->recording(['geo' => ['cc' => 'ch']])
			->setUrl(' https://geo.example/{ip} ')->setCountryPath('geo.cc')->setSubdivisionPath('')
			->setAuthHeader('Authorization: Bearer {key}')->setApiKey('t0k/en')->setTimeout('0.5');
		$this->assertSame('', $locator->getService());
		$this->assertSame('https://geo.example/{ip}', $locator->getUrl());
		$this->assertSame('geo.cc', $locator->getCountryPath());
		$this->assertSame('', $locator->getSubdivisionPath());
		$this->assertSame('Authorization: Bearer {key}', $locator->getAuthHeader());
		$this->assertSame('t0k/en', $locator->getApiKey());
		$this->assertSame('', $locator->getAccountId());
		$this->assertSame(0.5, $locator->getTimeout());
		$location = $locator->locate('2001:db8::1');
		$this->assertSame('CH', $location->getRegion());
		$this->assertSame('http', $location->getSource());
		$this->assertSame('https://geo.example/2001%3Adb8%3A%3A1', $locator->calls[0][0]);
		$this->assertSame(['Accept: application/json', 'Authorization: Bearer t0k/en'], $locator->calls[0][1]);
	}

	public function testFailuresAreUnknown()
	{
		$this->assertNull($this->recording(['country_code' => 'DE'], 429)->setService('ipapi')->locate('198.51.100.1'), 'a quota refusal');
		$this->assertNull($this->recording(['error' => 'x'])->setService('ipapi')->locate('198.51.100.1'), 'no country');
		$this->assertNull((new THttpIpLocator())->locate('198.51.100.1'), 'nothing configured');
	}

	public function testUnknownServiceAndBadTimeout()
	{
		try {
			(new THttpIpLocator())->setService('geocities');
			$this->fail('refused');
		} catch (TInvalidDataValueException $e) {
			$this->assertSame('privacy_ip_service_unknown', $e->getErrorCode());
		}
		$this->expectException(TInvalidDataValueException::class);
		(new THttpIpLocator())->setTimeout(0);
	}

	public function testTheRealTransportAgainstTheLoopback()
	{
		$server = LocalHttpServer::instance();
		$locator = (new THttpIpLocator())->setUrl($server->url('/{ip}?cc=us&rc=ca'))->setSubdivisionPath('region_code')
			->setAuthHeader('Authorization: Bearer {key}')->setApiKey('secret');
		$location = $locator->locate('198.51.100.1');
		$this->assertSame('US-CA', $location->getRegion());
		$failing = (new THttpIpLocator())->setUrl($server->url('/{ip}?status=503'));
		$this->assertNull($failing->locate('198.51.100.1'));
		$garbage = (new THttpIpLocator())->setUrl($server->url('/{ip}?body=not-json'));
		$this->assertNull($garbage->locate('198.51.100.1'));
		$closed = (new THttpIpLocator())->setUrl('http://127.0.0.1:1/{ip}')->setTimeout(0.5);
		$this->assertNull($closed->locate('198.51.100.1'), 'a refused connection is status 0');
	}
}
