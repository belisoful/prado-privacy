<?php

namespace belisoful\Privacy\Test\Unit\Consent\Region;

use belisoful\Privacy\Consent\Region\IIpLocator;
use belisoful\Privacy\Consent\Region\THttpIpLocator;
use belisoful\Privacy\Consent\Region\TIpLocation;
use belisoful\Privacy\Consent\Region\TIpRegionResolver;
use belisoful\Privacy\Consent\Region\TMaxMindDbLocator;
use belisoful\Privacy\Test\Unit\PrivacyTestCase;
use Prado\Caching\ICache;
use Prado\Exceptions\TConfigurationException;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\Prado;
use Prado\TComponent;
use Prado\TModule;
use Prado\Util\Log\TLogger;
use Prado\Xml\TXmlDocument;

class TIpRegionResolverTest extends PrivacyTestCase
{
	private RecordingLocator $locator;

	protected function setUp(): void
	{
		parent::setUp();
		$this->locator = new RecordingLocator();
	}

	private function resolver(): TIpRegionResolver
	{
		return (new TIpRegionResolver())->setLocator($this->locator);
	}

	public function testLocatesThePeerAddress()
	{
		$this->request->ip = '198.51.100.1';
		$this->assertSame('US-CA', $this->resolver()->resolveRegion($this->request));
		$this->assertSame(['198.51.100.1'], $this->locator->asked);
	}

	public function testPrivateReservedAndMissingAddressesAreUnknownWithoutALookup()
	{
		foreach (['10.1.2.3', '192.168.0.1', '127.0.0.1', '::1', 'fd00::1', '', 'not-an-ip'] as $ip) {
			$this->request->ip = $ip;
			$this->assertNull($this->resolver()->resolveRegion($this->request), $ip);
		}
		$this->assertSame([], $this->locator->asked);
	}

	public function testForwardedForIsReadOnlyBehindATrustedProxy()
	{
		$resolver = $this->resolver()->setTrustedProxies(' 10.0.0.0/8 , 2001:db8::/32, 192.0.2.10,');
		$this->assertSame(['10.0.0.0/8', '2001:db8::/32', '192.0.2.10'], $resolver->getTrustedProxies());
		$this->request->headers['X-Forwarded-For'] = '198.51.100.9, 203.0.113.50, 10.0.0.2';
		$this->request->ip = '10.0.0.1';
		$this->assertSame('203.0.113.50', $resolver->getClientAddress($this->request), 'the rightmost untrusted hop');
		$this->request->ip = '2001:db8::5';
		$this->assertSame('203.0.113.50', $resolver->getClientAddress($this->request));
		$this->request->ip = '203.0.113.1';
		$this->assertSame('203.0.113.1', $resolver->getClientAddress($this->request), 'an untrusted peer: the header is ignored');
		$this->request->ip = '192.0.2.10';
		$this->request->headers['X-Forwarded-For'] = '10.0.0.3, ';
		$this->assertSame('192.0.2.10', $resolver->getClientAddress($this->request), 'every hop trusted: the peer');
		$this->request->ip = '';
		$this->assertNull($resolver->getClientAddress($this->request));
	}

	public function testPortsBracketsAndQuotesAreRemovedFromHops()
	{
		$resolver = $this->resolver()->setTrustedProxies('10.0.0.0/8');
		$this->request->ip = '10.0.0.1';
		$this->request->headers['X-Forwarded-For'] = '"[2001:db8:ffff::1]:443", 203.0.113.50:5678, 10.0.0.2:80';
		$this->assertSame('203.0.113.50', $resolver->getClientAddress($this->request));
		$this->request->headers['X-Forwarded-For'] = '203.0.113.50, "[2001:db8::7]"';
		$this->assertSame('2001:db8::7', $resolver->getClientAddress($this->request));
		$this->request->headers['X-Forwarded-For'] = '[2001:db8::8';
		$this->assertSame('2001:db8::8', $resolver->getClientAddress($this->request), 'an unclosed bracket');
		$this->request->headers['X-Forwarded-For'] = '2001:db8::9';
		$this->assertSame('2001:db8::9', $resolver->getClientAddress($this->request), 'a bare IPv6 address keeps its colons');
	}

	public function testForwardedIsReadWithoutXForwardedFor()
	{
		$resolver = $this->resolver()->setTrustedProxies('10.0.0.0/8');
		$this->request->ip = '10.0.0.1';
		$this->request->headers['Forwarded'] = 'for=198.51.100.9, For="[2001:db8:cafe::17]:4711";proto=https, for=10.0.0.2;by=10.0.0.1';
		$this->assertSame('2001:db8:cafe::17', $resolver->getClientAddress($this->request), 'the rightmost untrusted for=');
		$this->request->headers['Forwarded'] = 'for=198.51.100.9, proto=https';
		$this->assertSame('unknown', $resolver->getClientAddress($this->request), 'an element without for= is no address');
		$this->assertNull($resolver->resolveRegion($this->request));
		$this->request->headers['Forwarded'] = 'for=198.51.100.1:8080, ';
		$this->assertSame('US-CA', $resolver->resolveRegion($this->request), 'an empty element is skipped');
		$this->request->headers['X-Forwarded-For'] = '203.0.113.50';
		$this->assertSame('203.0.113.50', $resolver->getClientAddress($this->request), 'X-Forwarded-For comes first');
		unset($this->request->headers['X-Forwarded-For'], $this->request->headers['Forwarded']);
		$this->assertSame('10.0.0.1', $resolver->getClientAddress($this->request), 'no proxy header: the peer');
		$this->request->ip = '203.0.113.1';
		$this->request->headers['Forwarded'] = 'for=198.51.100.9';
		$this->assertSame('203.0.113.1', $resolver->getClientAddress($this->request), 'an untrusted peer: Forwarded is ignored');
	}

	public function testIpv4MappedAddressesAreIpv4()
	{
		$this->assertSame('10.0.0.5', TIpRegionResolver::unmapAddress('::ffff:10.0.0.5'));
		$this->assertSame('2001:db8::1', TIpRegionResolver::unmapAddress('2001:db8::1'));
		$this->assertSame('10.0.0.5', TIpRegionResolver::unmapAddress('10.0.0.5'));
		$this->assertSame('nope', TIpRegionResolver::unmapAddress('nope'));
		$this->assertTrue(TIpRegionResolver::inRange('::ffff:10.0.0.5', '10.0.0.0/8'));
		$resolver = $this->resolver()->setTrustedProxies('10.0.0.0/8');
		$this->request->ip = '::ffff:10.0.0.1';
		$this->request->headers['X-Forwarded-For'] = '203.0.113.50, ::ffff:10.0.0.2';
		$this->assertSame('203.0.113.50', $resolver->getClientAddress($this->request), 'a mapped proxy peer and hop are trusted');
		$this->assertSame('US-CA', $resolver->resolveRegion($this->request));
		$untrusted = $this->resolver();
		$this->request->ip = '::ffff:10.0.0.1';
		$this->assertSame('10.0.0.1', $untrusted->getClientAddress($this->request));
		$this->assertNull($untrusted->resolveRegion($this->request), 'a mapped private address is private');
		$this->request->ip = '::FFFF:198.51.100.1';
		$this->assertSame('US-CA', $untrusted->resolveRegion($this->request));
		$this->assertSame(['203.0.113.50', '198.51.100.1'], $this->locator->asked, 'the locator gets IPv4');
	}

	public function testFailuresAreLoggedWithoutTheAddressAndUnknown()
	{
		Prado::getLogger()->deleteLogs();
		$this->request->ip = '198.51.100.1';
		$failing = new class () extends TComponent implements IIpLocator {
			public function locate(string $ip): ?TIpLocation
			{
				throw new \RuntimeException('cannot place ' . $ip);
			}
		};
		$this->assertNull((new TIpRegionResolver())->setLocator($failing)->resolveRegion($this->request), 'a throwing locator');
		$this->assertNull((new TIpRegionResolver())->setLocator((new TMaxMindDbLocator())->setFile('/no/such/file.mmdb'))->resolveRegion($this->request), 'a missing database file');
		$broken = new ArrayCache();
		$broken->failGet = true;
		$this->addModule('broken-cache', $broken);
		$this->assertNull($this->resolver()->setCacheID('broken-cache')->resolveRegion($this->request), 'a failing cache read');
		$this->assertSame([], $this->locator->asked);
		$unwritable = new ArrayCache();
		$unwritable->failSet = true;
		$this->addModule('unwritable-cache', $unwritable);
		$this->assertSame('US-CA', $this->resolver()->setCacheID('unwritable-cache')->resolveRegion($this->request), 'a failing cache write keeps the answer');
		$logs = \array_values(Prado::getLogger()->getLogs(TLogger::WARNING, TIpRegionResolver::class));
		$this->assertCount(4, $logs);
		$this->assertStringContainsString('cannot place [address]', $logs[0][0]);
		foreach ($logs as $log) {
			$this->assertStringNotContainsString('198.51.100.1', $log[0]);
		}
	}

	public function testCacheKeysSeparateResolvers()
	{
		$resolver = $this->resolver();
		$this->assertSame($resolver->getCacheKeyPrefix(), $this->resolver()->getCacheKeyPrefix(), 'the same locator, the same keys');
		$this->assertMatchesRegularExpression('/^' . \preg_quote(TIpRegionResolver::CACHE_PREFIX, '/') . '~[0-9a-f]{16}:$/', $resolver->getCacheKeyPrefix());
		$ipinfo = (new TIpRegionResolver())->setLocator((new THttpIpLocator())->setService('ipinfo'));
		$ipdata = (new TIpRegionResolver())->setLocator((new THttpIpLocator())->setService('ipdata'));
		$this->assertNotSame($ipinfo->getCacheKeyPrefix(), $ipdata->getCacheKeyPrefix(), 'another provider, other keys');
		$this->assertNotSame($resolver->getCacheKeyPrefix(), $ipinfo->getCacheKeyPrefix());
		$file1 = (new TIpRegionResolver())->setLocator((new TMaxMindDbLocator())->setFile('/a.mmdb'));
		$file2 = (new TIpRegionResolver())->setLocator((new TMaxMindDbLocator())->setFile('/b.mmdb'));
		$this->assertNotSame($file1->getCacheKeyPrefix(), $file2->getCacheKeyPrefix(), 'another database, other keys');
		$resolver->setID('geo-ip');
		$this->assertSame(TIpRegionResolver::CACHE_PREFIX . 'geo-ip:', $resolver->getCacheKeyPrefix(), 'a module id names the keys');
		$cache = new ArrayCache();
		$this->addModule('shared-cache', $cache);
		$this->request->ip = '198.51.100.1';
		$resolver->setCacheID('shared-cache')->resolveRegion($this->request);
		$this->assertCount(1, $cache->items);
		$this->assertStringStartsWith(TIpRegionResolver::CACHE_PREFIX . 'geo-ip:', \array_key_first($cache->items));
	}

	public function testTheProcessingActivityNamesAnApiProvider()
	{
		$this->assertSame([], (new TIpRegionResolver())->getProcessingActivities()[0]->getRecipients());
		$this->assertSame([], $this->resolver()->getProcessingActivities()[0]->getRecipients());
		$api = (new TIpRegionResolver())->setLocator((new THttpIpLocator())->setUrl('https://geo.example.net/{ip}'));
		$this->assertSame(['geo.example.net (IP geolocation provider)'], $api->getProcessingActivities()[0]->getRecipients());
		$preset = (new TIpRegionResolver())->setLocator((new THttpIpLocator())->setService('ipdata'));
		$this->assertSame(['ipdata (IP geolocation provider)'], $preset->getProcessingActivities()[0]->getRecipients());
	}

	public function testInRange()
	{
		$this->assertTrue(TIpRegionResolver::inRange('10.20.30.40', '10.0.0.0/8'));
		$this->assertTrue(TIpRegionResolver::inRange('10.20.30.40', '10.16.0.0/12'));
		$this->assertFalse(TIpRegionResolver::inRange('10.32.0.1', '10.16.0.0/12'));
		$this->assertTrue(TIpRegionResolver::inRange('192.0.2.10', '192.0.2.10'));
		$this->assertFalse(TIpRegionResolver::inRange('192.0.2.11', '192.0.2.10'));
		$this->assertTrue(TIpRegionResolver::inRange('2001:db8:1::1', '2001:db8::/32'));
		$this->assertFalse(TIpRegionResolver::inRange('2001:db9::1', '2001:db8::/32'));
		$this->assertTrue(TIpRegionResolver::inRange('1.2.3.4', '0.0.0.0/0'));
		$this->assertFalse(TIpRegionResolver::inRange('10.0.0.1', '2001:db8::/32'), 'families differ');
		$this->assertFalse(TIpRegionResolver::inRange('nope', '10.0.0.0/8'));
		$this->assertFalse(TIpRegionResolver::inRange('10.0.0.1', 'nope/8'));
	}

	public function testTrustedProxiesAreValidated()
	{
		foreach (['10.0.0.0/33', 'fe80::/129', 'proxy.example', '10.0.0.0/x'] as $range) {
			try {
				$this->resolver()->setTrustedProxies($range);
				$this->fail($range);
			} catch (TInvalidDataValueException $e) {
				$this->assertSame('privacy_proxy_range_invalid', $e->getErrorCode());
			}
		}
		$this->assertSame(['::1/128'], $this->resolver()->setTrustedProxies(['::1/128'])->getTrustedProxies());
	}

	public function testAnswersAndMissesAreCachedUnderAHash()
	{
		$cache = new ArrayCache();
		$this->addModule('geo-cache', $cache);
		$resolver = $this->resolver()->setCacheID('geo-cache')->setCacheExpire('600')->setMissCacheExpire(60);
		$this->assertSame('geo-cache', $resolver->getCacheID());
		$this->assertSame(600, $resolver->getCacheExpire());
		$this->assertSame(60, $resolver->getMissCacheExpire());
		$this->request->ip = '198.51.100.1';
		$this->assertSame('US-CA', $resolver->resolveRegion($this->request));
		$this->assertSame('US-CA', $resolver->resolveRegion($this->request));
		$this->assertCount(1, $this->locator->asked);
		$this->request->ip = '198.51.100.2';
		$this->assertNull($resolver->resolveRegion($this->request));
		$this->assertNull($resolver->resolveRegion($this->request));
		$this->assertCount(2, $this->locator->asked);
		$this->assertSame([600, 60], \array_column($cache->items, 1));
		foreach (\array_keys($cache->items) as $key) {
			$this->assertStringStartsWith(TIpRegionResolver::CACHE_PREFIX, $key);
			$this->assertStringNotContainsString('198.51', $key);
		}
	}

	public function testWithoutACacheEveryRequestLooksUp()
	{
		$resolver = $this->resolver();
		$this->assertNull($resolver->getCache());
		$this->request->ip = '198.51.100.1';
		$resolver->resolveRegion($this->request);
		$resolver->resolveRegion($this->request);
		$this->assertCount(2, $this->locator->asked);
	}

	public function testCacheIdMustNameACache()
	{
		$this->addModule('not-a-cache', new TIpRegionResolver());
		$this->expectException(TConfigurationException::class);
		$this->resolver()->setCacheID('not-a-cache')->getCache();
	}

	public function testCacheLifetimesCannotBeNegative()
	{
		$this->expectException(TInvalidDataValueException::class);
		$this->resolver()->setMissCacheExpire(-1);
	}

	public function testALocatorIsRequired()
	{
		$this->request->ip = '198.51.100.1';
		$this->expectException(TConfigurationException::class);
		(new TIpRegionResolver())->setLocator('')->resolveRegion($this->request);
	}

	public function testLocatorFromConfiguration()
	{
		$doc = new TXmlDocument();
		$doc->loadFromString('<module><locator class="belisoful\Privacy\Consent\Region\TMaxMindDbLocator" File="/x.mmdb" /></module>');
		$resolver = new TIpRegionResolver();
		$resolver->init($doc);
		$this->assertInstanceOf(TMaxMindDbLocator::class, $resolver->getLocator());
		$this->assertSame('/x.mmdb', $resolver->getLocator()->getFile());
		$php = new TIpRegionResolver();
		$php->init(['locator' => $this->locator]);
		$this->assertSame($this->locator, $php->getLocator());
		$none = new TIpRegionResolver();
		$none->init(null);
		$none->init(new TXmlDocument());
		$this->expectException(TConfigurationException::class);
		$none->getLocator();
	}
}

/**
 * Places 198.51.100.1 in California and nothing else; records each address asked.
 */
class RecordingLocator extends TComponent implements IIpLocator
{
	public array $asked = [];

	public function locate(string $ip): ?TIpLocation
	{
		$this->asked[] = $ip;
		return $ip === '198.51.100.1' || $ip === '203.0.113.50' ? new TIpLocation('US', 'CA', 'test') : null;
	}
}

/**
 * An ICache over an array; records the expiry of each set.
 */
class ArrayCache extends TModule implements ICache
{
	/** @var array<string, array{0: mixed, 1: int}> */
	public array $items = [];

	public bool $failGet = false;

	public bool $failSet = false;

	public function get($id)
	{
		if ($this->failGet) {
			throw new \RuntimeException('the cache is down');
		}
		return $this->items[$id][0] ?? false;
	}

	public function set($id, $value, $expire = 0, $dependency = null)
	{
		if ($this->failSet) {
			throw new \RuntimeException('the cache is full');
		}
		$this->items[$id] = [$value, $expire];
		return true;
	}

	public function add($id, $value, $expire = 0, $dependency = null)
	{
		return $this->set($id, $value, $expire);
	}

	public function delete($id)
	{
		unset($this->items[$id]);
		return true;
	}

	public function flush()
	{
		$this->items = [];
		return true;
	}

	public static function getIsAvailable(): bool
	{
		return true;
	}
}
