<?php

/**
 * TIpLocation class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Consent\Region;

use Prado\TComponent;

/**
 * TIpLocation class.
 *
 * TIpLocation is where an `IIpLocator` placed an address: the `Country` (ISO 3166-1 alpha-2),
 * the `Subdivision` (the part after the dash of an ISO 3166-2 code: `CA` for California), and the
 * `Source` that answered. `getRegion()` gives the code `TConsentModule` uses: `US-CA`, or `DE`
 * without a subdivision.
 *
 * {@see fromData()} reads a location out of a decoded answer by dot paths, so one class reads
 * every database and API format. It accepts a subdivision as a bare code (`CA`), a full code
 * (`US-CA`), or, for the United States, a state name (`California`), which several APIs return
 * instead of a code.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 */
class TIpLocation extends TComponent
{
	/** US state and district names => their ISO 3166-2 subdivision codes. */
	public const US_STATES = [
		'alabama' => 'AL', 'alaska' => 'AK', 'arizona' => 'AZ', 'arkansas' => 'AR', 'california' => 'CA',
		'colorado' => 'CO', 'connecticut' => 'CT', 'delaware' => 'DE', 'district of columbia' => 'DC',
		'florida' => 'FL', 'georgia' => 'GA', 'hawaii' => 'HI', 'idaho' => 'ID', 'illinois' => 'IL',
		'indiana' => 'IN', 'iowa' => 'IA', 'kansas' => 'KS', 'kentucky' => 'KY', 'louisiana' => 'LA',
		'maine' => 'ME', 'maryland' => 'MD', 'massachusetts' => 'MA', 'michigan' => 'MI', 'minnesota' => 'MN',
		'mississippi' => 'MS', 'missouri' => 'MO', 'montana' => 'MT', 'nebraska' => 'NE', 'nevada' => 'NV',
		'new hampshire' => 'NH', 'new jersey' => 'NJ', 'new mexico' => 'NM', 'new york' => 'NY',
		'north carolina' => 'NC', 'north dakota' => 'ND', 'ohio' => 'OH', 'oklahoma' => 'OK', 'oregon' => 'OR',
		'pennsylvania' => 'PA', 'rhode island' => 'RI', 'south carolina' => 'SC', 'south dakota' => 'SD',
		'tennessee' => 'TN', 'texas' => 'TX', 'utah' => 'UT', 'vermont' => 'VT', 'virginia' => 'VA',
		'washington' => 'WA', 'west virginia' => 'WV', 'wisconsin' => 'WI', 'wyoming' => 'WY',
	];

	/**
	 * @param string $_country the ISO 3166-1 alpha-2 country code
	 * @param ?string $_subdivision the subdivision code after the dash (`CA`); null when unknown
	 * @param string $_source the locator that answered
	 */
	public function __construct(private string $_country, private ?string $_subdivision = null, private string $_source = '')
	{
		parent::__construct();
	}

	/**
	 * @return string the ISO 3166-1 alpha-2 country code
	 */
	public function getCountry(): string
	{
		return $this->_country;
	}

	/**
	 * @return ?string the subdivision code after the dash (`CA`); null when unknown
	 */
	public function getSubdivision(): ?string
	{
		return $this->_subdivision;
	}

	/**
	 * @return string the locator that answered
	 */
	public function getSource(): string
	{
		return $this->_source;
	}

	/**
	 * @return string the region code: `US-CA`, or the country alone without a subdivision
	 */
	public function getRegion(): string
	{
		return $this->_subdivision === null ? $this->_country : $this->_country . '-' . $this->_subdivision;
	}

	/**
	 * Reads a location out of a decoded answer.
	 * @param mixed $data the decoded answer
	 * @param string $countryPath the dot path to the country code (`country.iso_code`)
	 * @param string $subdivisionPath the dot path to the subdivision (`subdivisions.0.iso_code`); empty for none
	 * @param string $source the locator that answered
	 * @return ?TIpLocation the location; null without a two-letter country code
	 */
	public static function fromData(mixed $data, string $countryPath, string $subdivisionPath, string $source): ?TIpLocation
	{
		$country = \strtoupper(\trim((string) self::valueAt($data, $countryPath)));
		if (!\preg_match('/^[A-Z]{2}$/', $country)) {
			return null;
		}
		$subdivision = \trim((string) ($subdivisionPath === '' ? '' : self::valueAt($data, $subdivisionPath)));
		return new self($country, self::normalizeSubdivision($country, $subdivision), $source);
	}

	/**
	 * @param string $country the country code
	 * @param string $subdivision a bare code, a full code, or a US state name
	 * @return ?string the subdivision code after the dash; null when it cannot be read
	 */
	public static function normalizeSubdivision(string $country, string $subdivision): ?string
	{
		$code = \strtoupper($subdivision);
		if (\str_starts_with($code, $country . '-')) {
			$code = \substr($code, 3);
		}
		if (\preg_match('/^[A-Z0-9]{1,3}$/', $code)) {
			return $code;
		}
		return $country === 'US' ? (self::US_STATES[\strtolower($subdivision)] ?? null) : null;
	}

	/**
	 * @param mixed $data a decoded structure
	 * @param string $path a dot path; a numeric segment indexes a list
	 * @return mixed the value at the path; null when a segment is missing
	 */
	public static function valueAt(mixed $data, string $path): mixed
	{
		foreach (\explode('.', $path) as $segment) {
			if (!\is_array($data) || !\array_key_exists($segment, $data)) {
				return null;
			}
			$data = $data[$segment];
		}
		return \is_scalar($data) ? $data : null;
	}
}
