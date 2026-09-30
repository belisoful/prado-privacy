<?php

/**
 * THttpIpLocator class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Consent\Region;

use Prado\Exceptions\TInvalidDataValueException;
use Prado\Prado;
use Prado\TComponent;
use Prado\TPropertyValue;
use Prado\Util\Log\TLogger;

/**
 * THttpIpLocator class.
 *
 * THttpIpLocator locates an address through any IP intelligence API that answers JSON. `Url` is
 * a template: `{ip}`, `{key}` (`ApiKey`) and `{account}` (`AccountId`) are replaced, URL-encoded.
 * `AuthHeader` adds a header line with the same placeholders, for APIs that take a bearer token.
 * `CountryPath` and `SubdivisionPath` are dot paths into the answer (`location.country.code`).
 *
 * `Service` fills in the URL and the paths for a known provider; a property set afterwards
 * overrides the preset:
 *
 * | Service | Provider | Answer |
 * |---|---|---|
 * | `maxmind` | MaxMind GeoIP2 Precision City web service (basic auth: `AccountId`, `ApiKey`) | `country.iso_code`, `subdivisions.0.iso_code` |
 * | `ipinfo` | IPinfo | `country`, `region` (a state name) |
 * | `ipinfo-lite` | IPinfo Lite (country only, free) | `country_code` |
 * | `ipdata` | ipdata.co | `country_code`, `region_code` |
 * | `ipgeolocation` | ipgeolocation.io | `country_code2`, `state_code` |
 * | `ipregistry` | Ipregistry | `location.country.code`, `location.region.code` |
 * | `ipapi` | ipapi.co | `country_code`, `region_code` |
 * | `ipstack` | ipstack | `country_code`, `region_code` |
 * | `ip2location` | IP2Location.io | `country_code`, `region_name` (a state name) |
 * | `abstract` | Abstract API IP geolocation | `country_code`, `region_iso_code` |
 * | `bigdatacloud` | BigDataCloud | `country.isoAlpha2`, `location.principalSubdivisionCode` |
 * | `dbip` | DB-IP (`ApiKey` empty uses the free endpoint) | `countryCode`, `stateProvCode` |
 *
 * Endpoints, quotas and terms change; check the provider's current documentation, and configure
 * `Url` and the paths directly for a provider or an endpoint without a preset.
 *
 * Any answer other than HTTP 200 with a country (a timeout, a quota refusal, malformed JSON) is
 * logged at `TLogger::WARNING` under this class and gives null. The lookup sends the visitor's IP
 * address to the provider: list the provider as a recipient in the records of processing.
 *
 * ```xml
 * <module id="geo-ip" class="belisoful\Privacy\Consent\Region\TIpRegionResolver" TrustedProxies="10.0.0.0/8">
 *   <locator class="belisoful\Privacy\Consent\Region\THttpIpLocator" Service="ipinfo" ApiKey="…" />
 * </module>
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 */
class THttpIpLocator extends TComponent implements IIpLocator
{
	use THttpTransportTrait;

	/** The default timeout, in seconds. */
	public const DEFAULT_TIMEOUT = 1.5;

	/** Service => [url, country path, subdivision path, auth header, key when none is set]. */
	public const SERVICES = [
		'maxmind' => ['https://{account}:{key}@geoip.maxmind.com/geoip/v2.1/city/{ip}', 'country.iso_code', 'subdivisions.0.iso_code', '', ''],
		'ipinfo' => ['https://ipinfo.io/{ip}/json?token={key}', 'country', 'region', '', ''],
		'ipinfo-lite' => ['https://api.ipinfo.io/lite/{ip}?token={key}', 'country_code', '', '', ''],
		'ipdata' => ['https://api.ipdata.co/{ip}?api-key={key}', 'country_code', 'region_code', '', ''],
		'ipgeolocation' => ['https://api.ipgeolocation.io/ipgeo?apiKey={key}&ip={ip}', 'country_code2', 'state_code', '', ''],
		'ipregistry' => ['https://api.ipregistry.co/{ip}?key={key}', 'location.country.code', 'location.region.code', '', ''],
		'ipapi' => ['https://ipapi.co/{ip}/json/', 'country_code', 'region_code', '', ''],
		'ipstack' => ['https://api.ipstack.com/{ip}?access_key={key}', 'country_code', 'region_code', '', ''],
		'ip2location' => ['https://api.ip2location.io/?key={key}&ip={ip}', 'country_code', 'region_name', '', ''],
		'abstract' => ['https://ipgeolocation.abstractapi.com/v1/?api_key={key}&ip_address={ip}', 'country_code', 'region_iso_code', '', ''],
		'bigdatacloud' => ['https://api-bdc.net/data/ip-geolocation?ip={ip}&key={key}', 'country.isoAlpha2', 'location.principalSubdivisionCode', '', ''],
		'dbip' => ['https://api.db-ip.com/v2/{key}/{ip}', 'countryCode', 'stateProvCode', '', 'free'],
	];

	/** @var string the preset in use; empty for none */
	private string $_service = '';

	/** @var string the URL template */
	private string $_url = '';

	/** @var string the API key */
	private string $_apiKey = '';

	/** @var string the account id */
	private string $_accountId = '';

	/** @var string the authentication header template */
	private string $_authHeader = '';

	/** @var string the dot path to the country code */
	private string $_countryPath = 'country_code';

	/** @var string the dot path to the subdivision */
	private string $_subdivisionPath = '';

	/** @var string the key used when ApiKey is empty */
	private string $_defaultKey = '';

	/** @var float the timeout in seconds */
	private float $_timeout = self::DEFAULT_TIMEOUT;

	/**
	 * Looks the address up with the provider.
	 * @param string $ip an IPv4 or IPv6 address
	 * @return ?TIpLocation the location; null when the provider cannot place it or the lookup fails
	 */
	public function locate(string $ip): ?TIpLocation
	{
		if ($this->getUrl() === '') {
			Prado::log('THttpIpLocator has no Url or Service; no lookup is made.', TLogger::WARNING, static::class);
			return null;
		}
		$url = $this->expand($this->getUrl(), $ip, true);
		$headers = ['Accept: application/json'];
		if ($this->getAuthHeader() !== '') {
			$headers[] = $this->expand($this->getAuthHeader(), $ip, false);
		}
		[$status, $body] = $this->transport($url, $headers, $this->getTimeout());
		$location = $status === 200 ? TIpLocation::fromData(\json_decode((string) $body, true), $this->getCountryPath(), $this->getSubdivisionPath(), $this->getService() ?: 'http') : null;
		if ($location === null) {
			Prado::log('The IP lookup (' . ($this->getService() ?: 'http') . ') answered status ' . $status . ' without a country; the region is unknown.', TLogger::WARNING, static::class);
		}
		return $location;
	}

	/**
	 * @param string $template a template with `{ip}`, `{key}` and `{account}`
	 * @param string $ip the address
	 * @param bool $encode whether the values are URL-encoded
	 * @return string the template with the values
	 */
	protected function expand(string $template, string $ip, bool $encode): string
	{
		$values = ['{ip}' => $ip, '{key}' => $this->getApiKey() ?: $this->_defaultKey, '{account}' => $this->getAccountId()];
		return \strtr($template, $encode ? \array_map('rawurlencode', $values) : $values);
	}

	/**
	 * @return string the preset in use; empty for none
	 */
	public function getService(): string
	{
		return $this->_service;
	}

	/**
	 * Applies a provider preset: the URL, the paths and the authentication header.
	 * @param mixed $value a key of {@see SERVICES}
	 * @throws TInvalidDataValueException when the service is unknown
	 * @return static the locator
	 */
	public function setService($value): static
	{
		$value = \strtolower(\trim(TPropertyValue::ensureString($value)));
		if (!isset(self::SERVICES[$value])) {
			throw new TInvalidDataValueException('privacy_ip_service_unknown', $value, \implode(', ', \array_keys(self::SERVICES)));
		}
		[$this->_url, $this->_countryPath, $this->_subdivisionPath, $this->_authHeader, $this->_defaultKey] = self::SERVICES[$value];
		$this->_service = $value;
		return $this;
	}

	/**
	 * @return string the URL template
	 */
	public function getUrl(): string
	{
		return $this->_url;
	}

	/**
	 * @param mixed $value the URL template, with `{ip}`, `{key}` and `{account}`
	 * @return static the locator
	 */
	public function setUrl($value): static
	{
		$this->_url = \trim(TPropertyValue::ensureString($value));
		return $this;
	}

	/**
	 * @return string the API key
	 */
	public function getApiKey(): string
	{
		return $this->_apiKey;
	}

	/**
	 * @param mixed $value the API key (or licence key, token)
	 * @return static the locator
	 */
	public function setApiKey($value): static
	{
		$this->_apiKey = \trim(TPropertyValue::ensureString($value));
		return $this;
	}

	/**
	 * @return string the account id
	 */
	public function getAccountId(): string
	{
		return $this->_accountId;
	}

	/**
	 * @param mixed $value the account id, for providers that authenticate with an account and a key
	 * @return static the locator
	 */
	public function setAccountId($value): static
	{
		$this->_accountId = \trim(TPropertyValue::ensureString($value));
		return $this;
	}

	/**
	 * @return string the authentication header template
	 */
	public function getAuthHeader(): string
	{
		return $this->_authHeader;
	}

	/**
	 * @param mixed $value a header line template, such as `Authorization: Bearer {key}`; empty for none
	 * @return static the locator
	 */
	public function setAuthHeader($value): static
	{
		$this->_authHeader = \trim(TPropertyValue::ensureString($value));
		return $this;
	}

	/**
	 * @return string the dot path to the country code
	 */
	public function getCountryPath(): string
	{
		return $this->_countryPath;
	}

	/**
	 * @param mixed $value the dot path to the country code in the answer
	 * @return static the locator
	 */
	public function setCountryPath($value): static
	{
		$this->_countryPath = \trim(TPropertyValue::ensureString($value));
		return $this;
	}

	/**
	 * @return string the dot path to the subdivision; empty for none
	 */
	public function getSubdivisionPath(): string
	{
		return $this->_subdivisionPath;
	}

	/**
	 * @param mixed $value the dot path to the subdivision in the answer; empty for none
	 * @return static the locator
	 */
	public function setSubdivisionPath($value): static
	{
		$this->_subdivisionPath = \trim(TPropertyValue::ensureString($value));
		return $this;
	}

	/**
	 * @return float the timeout in seconds
	 */
	public function getTimeout(): float
	{
		return $this->_timeout;
	}

	/**
	 * @param mixed $value the timeout in seconds, above 0
	 * @throws TInvalidDataValueException when the value is not above 0
	 * @return static the locator
	 */
	public function setTimeout($value): static
	{
		$value = TPropertyValue::ensureFloat($value);
		if ($value <= 0) {
			throw new TInvalidDataValueException('privacy_timeout_invalid', $value);
		}
		$this->_timeout = $value;
		return $this;
	}
}
