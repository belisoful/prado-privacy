<?php

/**
 * TMaxMindDbLocator class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Consent\Region;

use Prado\Exceptions\TConfigurationException;
use Prado\Prado;
use Prado\TComponent;
use Prado\TPropertyValue;
use Prado\Util\Log\TLogger;

/**
 * TMaxMindDbLocator class.
 *
 * TMaxMindDbLocator locates an address in a local file in the MaxMind DB format, with the
 * `maxmind-db/reader` package (suggested, not required). The lookup stays on the server: the IP
 * address goes to no third party.
 *
 * | Database | Licence | `CountryPath` / `SubdivisionPath` |
 * |---|---|---|
 * | MaxMind GeoLite2 Country or City | free with an account (GeoLite2 EULA) | the defaults |
 * | MaxMind GeoIP2 Country or City | commercial | the defaults |
 * | DB-IP IP to Country or City Lite | free, CC BY 4.0 (attribution required) | the defaults |
 * | IPinfo free country database | free, CC BY-SA 4.0 | `country` / empty |
 * | IP2Location LITE (mmdb) | free, CC BY-SA 4.0 | `country.iso_code` / `subdivisions.0.iso_code` |
 *
 * The operator downloads and refreshes the file (MaxMind's `geoipupdate`, or a cron job); the
 * locator only reads it. `File` accepts a path or a PRADO namespace path. The reader opens on the
 * first lookup.
 *
 * ```xml
 * <module id="geo-ip" class="belisoful\Privacy\Consent\Region\TIpRegionResolver">
 *   <locator class="belisoful\Privacy\Consent\Region\TMaxMindDbLocator" File="/var/lib/GeoIP/GeoLite2-City.mmdb" />
 * </module>
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TMaxMindDbLocator extends TComponent implements IIpLocator
{
	/** The reader class of maxmind-db/reader. */
	public const READER_CLASS = 'MaxMind\Db\Reader';

	/** @var string the database file */
	private string $_file = '';

	/** @var string the dot path to the country code */
	private string $_countryPath = 'country.iso_code';

	/** @var string the dot path to the subdivision */
	private string $_subdivisionPath = 'subdivisions.0.iso_code';

	/** @var ?object the reader */
	private ?object $_reader = null;

	/**
	 * Looks the address up in the database.
	 * @param string $ip an IPv4 or IPv6 address
	 * @throws TConfigurationException when the file or the reader package is missing
	 * @return ?TIpLocation the location; null when the database has no country for the address
	 */
	public function locate(string $ip): ?TIpLocation
	{
		try {
			$data = $this->getReader()->get($ip);
		} catch (\InvalidArgumentException $e) {
			// An IPv6 address in an IPv4-only database, or an address the reader refuses.
			Prado::log('The MaxMind DB lookup refused "' . $ip . '": ' . $e->getMessage(), TLogger::WARNING, static::class);
			return null;
		}
		return TIpLocation::fromData($data, $this->getCountryPath(), $this->getSubdivisionPath(), 'mmdb');
	}

	/**
	 * @throws TConfigurationException when the file or the reader package is missing
	 * @return object the reader, opened on first use
	 */
	public function getReader(): object
	{
		if ($this->_reader === null) {
			$file = $this->getFile();
			if ($file === '' || !\is_file($file)) {
				throw new TConfigurationException('privacy_mmdb_file_missing', $file);
			}
			$class = $this->getReaderClass();
			if (!\class_exists($class)) {
				throw new TConfigurationException('privacy_mmdb_reader_missing', $class);
			}
			$this->_reader = new $class($file);
		}
		return $this->_reader;
	}

	/**
	 * @return string the reader class; `MaxMind\Db\Reader`
	 */
	protected function getReaderClass(): string
	{
		return self::READER_CLASS;
	}

	/**
	 * @return string the database file
	 */
	public function getFile(): string
	{
		return $this->_file;
	}

	/**
	 * @param mixed $value the database file: a path, or a PRADO namespace path
	 * @return static the locator
	 */
	public function setFile($value): static
	{
		$value = \trim(TPropertyValue::ensureString($value));
		$this->_file = (\is_file($value) ? $value : Prado::getPathOfNamespace($value)) ?? $value;
		$this->_reader = null;
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
	 * @param mixed $value the dot path to the country code in a record
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
	 * @param mixed $value the dot path to the subdivision in a record; empty for none
	 * @return static the locator
	 */
	public function setSubdivisionPath($value): static
	{
		$this->_subdivisionPath = \trim(TPropertyValue::ensureString($value));
		return $this;
	}
}
