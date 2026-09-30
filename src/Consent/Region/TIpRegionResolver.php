<?php

/**
 * TIpRegionResolver class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Consent\Region;

use belisoful\Privacy\Retention\IProcessingActivityProvider;
use belisoful\Privacy\Retention\TLegalBasis;
use belisoful\Privacy\Retention\TProcessingActivity;
use belisoful\Privacy\TConfiguredComponentTrait;
use belisoful\Privacy\TPrivacyHash;
use Prado\Caching\ICache;
use Prado\Exceptions\TConfigurationException;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\Prado;
use Prado\TModule;
use Prado\TPropertyValue;
use Prado\Web\THttpRequest;
use Prado\Xml\TXmlElement;

/**
 * TIpRegionResolver class.
 *
 * TIpRegionResolver places a request by the client's IP address, through an `IIpLocator`: a local
 * database (`TMaxMindDbLocator`), a web API (`THttpIpLocator`), or a locator an application or
 * another extension supplies. It is a module, so a chain or several consent modules can share one.
 *
 * 1. The client address: `REMOTE_ADDR`, or, when that is one of the `TrustedProxies` (CIDR list),
 *    the rightmost address of `X-Forwarded-For` that is not a trusted proxy. Proxy headers from
 *    any other peer are ignored, because a client can send them.
 * 2. Private, loopback and reserved addresses are unknown without a lookup.
 * 3. The cache (`CacheID`, or the application cache when one is configured) holds each answer for
 *    `CacheExpire` seconds (one day) and each miss for `MissCacheExpire` (one hour), under an HMAC
 *    of the address, so the cache holds no address in clear.
 * 4. The locator answers; a failure is unknown. An unknown region means the visitor is asked for
 *    consent: the resolver fails closed.
 *
 * The resolver declares its processing activity to `TProcessingRegistry`, with the provider of a
 * `THttpIpLocator` as a recipient.
 *
 * ```xml
 * <module id="geo-ip" class="belisoful\Privacy\Consent\Region\TIpRegionResolver" TrustedProxies="10.0.0.0/8, 2001:db8::/32">
 *   <locator class="belisoful\Privacy\Consent\Region\TMaxMindDbLocator" File="/var/lib/GeoIP/GeoLite2-City.mmdb" />
 * </module>
 * <module id="belisoful/prado-privacy" RegionResolver="geo-ip" />
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 */
class TIpRegionResolver extends TModule implements IRegionResolver, IProcessingActivityProvider
{
	use TConfiguredComponentTrait;

	/** The prefix of the resolver's cache keys. */
	public const CACHE_PREFIX = 'prado-privacy:ip-region:';

	/** @var mixed the locator, or its configuration until first use */
	private mixed $_locator = null;

	/** @var string[] the trusted proxies, as CIDR ranges */
	private array $_trustedProxies = [];

	/** @var string the id of the cache module; empty for the application cache */
	private string $_cacheID = '';

	/** @var int seconds an answer is cached */
	private int $_cacheExpire = 86400;

	/** @var int seconds a miss is cached */
	private int $_missCacheExpire = 3600;

	/**
	 * Reads a `<locator>` element (or the `locator` key of a PHP configuration).
	 * @param null|array|TXmlElement $config the module configuration
	 */
	public function init($config)
	{
		if ($config instanceof TXmlElement && ($element = $config->getElementByTagName('locator')) !== null) {
			$this->setLocator($element->getAttributes()->toArray());
		} elseif (\is_array($config) && isset($config['locator'])) {
			$this->setLocator($config['locator']);
		}
		parent::init($config);
	}

	/**
	 * Places the request by its client address.
	 * @param THttpRequest $request the request
	 * @throws TConfigurationException when no locator is configured
	 * @return ?string the region; null for a private or reserved address, or when the locator cannot place it
	 */
	public function resolveRegion(THttpRequest $request): ?string
	{
		$ip = $this->getClientAddress($request);
		if ($ip === null || \filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
			return null;
		}
		$cache = $this->getCache();
		$key = self::CACHE_PREFIX . TPrivacyHash::hash($ip, TPrivacyHash::CACHE);
		$cached = $cache?->get($key);
		if (\is_string($cached)) {
			return $cached === '' ? null : $cached;
		}
		$region = $this->getLocator()->locate($ip)?->getRegion();
		$cache?->set($key, $region ?? '', $region === null ? $this->getMissCacheExpire() : $this->getCacheExpire());
		return $region;
	}

	/**
	 * @return TProcessingActivity[] locating the visitor to apply the privacy law of their region
	 */
	public function getProcessingActivities(): array
	{
		$locator = $this->_locator === null ? null : $this->getLocator();
		$recipient = '';
		if ($locator instanceof THttpIpLocator) {
			$recipient = ($locator->getService() ?: (string) \parse_url($locator->getUrl(), PHP_URL_HOST)) . ' (IP geolocation provider)';
		}
		return [new TProcessingActivity([
			'Name' => 'region-resolution',
			'Purpose' => 'Locating the visitor by IP address to apply the privacy law of their region',
			'LegalBasis' => TLegalBasis::LegitimateInterests,
			'LegalBasisNote' => 'applying the consent rules the visitor\'s law requires (GDPR Art. 6(1)(f), with 6(1)(c))',
			'SubjectCategories' => 'website visitors',
			'DataCategories' => 'IP address, derived country and subdivision',
			'Recipients' => $recipient,
			'RetentionPeriod' => 'the region is cached ' . $this->getCacheExpire() . ' seconds under an HMAC of the address; the address is not stored',
		])];
	}

	/**
	 * @param THttpRequest $request the request
	 * @return ?string the client address: the peer, or the rightmost untrusted `X-Forwarded-For` hop behind a trusted proxy; null when there is none
	 */
	public function getClientAddress(THttpRequest $request): ?string
	{
		$peer = \trim((string) $request->getUserHostAddress());
		if ($peer === '' || !$this->isTrustedProxy($peer)) {
			return $peer === '' ? null : $peer;
		}
		$hops = \array_reverse(\array_map('trim', \explode(',', (string) $request->getHeader('X-Forwarded-For'))));
		foreach ($hops as $hop) {
			if ($hop !== '' && !$this->isTrustedProxy($hop)) {
				return $hop;
			}
		}
		return $peer;
	}

	/**
	 * @param string $ip an address
	 * @return bool whether the address is in one of the `TrustedProxies`
	 */
	public function isTrustedProxy(string $ip): bool
	{
		foreach ($this->_trustedProxies as $range) {
			if (self::inRange($ip, $range)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string $ip an IPv4 or IPv6 address
	 * @param string $range a CIDR range (`10.0.0.0/8`, `2001:db8::/32`) or a single address
	 * @return bool whether the address is in the range; false for an invalid address or range
	 */
	public static function inRange(string $ip, string $range): bool
	{
		[$subnet, $bits] = \array_pad(\explode('/', $range, 2), 2, null);
		$address = @\inet_pton($ip);
		$network = @\inet_pton((string) $subnet);
		if ($address === false || $network === false || \strlen($address) !== \strlen($network)) {
			return false;
		}
		$bits = $bits === null ? \strlen($address) * 8 : (int) $bits;
		$bytes = \intdiv($bits, 8);
		if (\substr($address, 0, $bytes) !== \substr($network, 0, $bytes)) {
			return false;
		}
		$remainder = $bits % 8;
		if ($remainder === 0) {
			return true;
		}
		$mask = (0xFF << (8 - $remainder)) & 0xFF;
		return (\ord($address[$bytes]) & $mask) === (\ord($network[$bytes]) & $mask);
	}

	/**
	 * @throws TConfigurationException when no locator is configured, or it does not implement `IIpLocator`
	 * @return IIpLocator the locator
	 */
	public function getLocator(): IIpLocator
	{
		if (!($this->_locator instanceof IIpLocator)) {
			if ($this->_locator === null) {
				throw new TConfigurationException('privacy_ip_locator_missing', static::class);
			}
			$this->_locator = $this->resolveConfiguredComponent($this->_locator, IIpLocator::class);
		}
		return $this->_locator;
	}

	/**
	 * @param mixed $value a locator, a configuration array (`class` and properties), or a module id
	 * @return static the resolver
	 */
	public function setLocator($value): static
	{
		$this->_locator = ($value === '' ? null : $value);
		return $this;
	}

	/**
	 * @return string[] the trusted proxies, as CIDR ranges
	 */
	public function getTrustedProxies(): array
	{
		return $this->_trustedProxies;
	}

	/**
	 * @param mixed $value CIDR ranges or addresses, comma-separated or an array
	 * @throws TInvalidDataValueException when an entry is not an address or a CIDR range
	 * @return static the resolver
	 */
	public function setTrustedProxies($value): static
	{
		$list = \is_array($value) ? $value : \explode(',', (string) $value);
		$ranges = [];
		foreach ($list as $range) {
			$range = \trim((string) $range);
			if ($range === '') {
				continue;
			}
			[$subnet, $bits] = \array_pad(\explode('/', $range, 2), 2, null);
			$max = \str_contains((string) $subnet, ':') ? 128 : 32;
			if (\filter_var($subnet, FILTER_VALIDATE_IP) === false || ($bits !== null && (!\ctype_digit($bits) || (int) $bits > $max))) {
				throw new TInvalidDataValueException('privacy_proxy_range_invalid', $range);
			}
			$ranges[] = $range;
		}
		$this->_trustedProxies = $ranges;
		return $this;
	}

	/**
	 * @throws TConfigurationException when CacheID names a module that is not a cache
	 * @return ?ICache the cache; the application cache without a CacheID; null without either
	 */
	public function getCache(): ?ICache
	{
		if ($this->_cacheID === '') {
			return $this->getApplication()->getCache();
		}
		$cache = $this->getApplication()->getModule($this->_cacheID);
		if (!($cache instanceof ICache)) {
			throw new TConfigurationException('privacy_component_invalid', $this->_cacheID, ICache::class);
		}
		return $cache;
	}

	/**
	 * @return string the id of the cache module; empty for the application cache
	 */
	public function getCacheID(): string
	{
		return $this->_cacheID;
	}

	/**
	 * @param mixed $value the id of the cache module; empty for the application cache
	 * @return static the resolver
	 */
	public function setCacheID($value): static
	{
		$this->_cacheID = \trim(TPropertyValue::ensureString($value));
		return $this;
	}

	/**
	 * @return int seconds an answer is cached
	 */
	public function getCacheExpire(): int
	{
		return $this->_cacheExpire;
	}

	/**
	 * @param mixed $value seconds an answer is cached; 0 caches until the cache evicts it
	 * @throws TInvalidDataValueException when the value is negative
	 * @return static the resolver
	 */
	public function setCacheExpire($value): static
	{
		$this->_cacheExpire = self::ensureSeconds($value);
		return $this;
	}

	/**
	 * @return int seconds a miss is cached
	 */
	public function getMissCacheExpire(): int
	{
		return $this->_missCacheExpire;
	}

	/**
	 * @param mixed $value seconds a miss is cached; 0 caches until the cache evicts it
	 * @throws TInvalidDataValueException when the value is negative
	 * @return static the resolver
	 */
	public function setMissCacheExpire($value): static
	{
		$this->_missCacheExpire = self::ensureSeconds($value);
		return $this;
	}

	/**
	 * @param mixed $value a number of seconds
	 * @throws TInvalidDataValueException when the value is negative
	 * @return int the seconds
	 */
	protected static function ensureSeconds(mixed $value): int
	{
		$value = TPropertyValue::ensureInteger($value);
		if ($value < 0) {
			throw new TInvalidDataValueException('privacy_cache_expire_invalid', $value);
		}
		return $value;
	}
}
