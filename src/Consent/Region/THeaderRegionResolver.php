<?php

/**
 * THeaderRegionResolver class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Consent\Region;

use Prado\TComponent;
use Prado\TPropertyValue;
use Prado\Web\THttpRequest;

/**
 * THeaderRegionResolver class.
 *
 * THeaderRegionResolver reads the visitor's country, and optionally the subdivision, from
 * headers a CDN or a reverse proxy adds. It costs no lookup and sends the IP address nowhere.
 *
 * | CDN | Country header | Subdivision header |
 * |---|---|---|
 * | Cloudflare | `CF-IPCountry` | `cf-region-code` (with the "Add visitor location headers" transform) |
 * | Amazon CloudFront | `CloudFront-Viewer-Country` | `CloudFront-Viewer-Country-Region` |
 * | Fastly, nginx GeoIP, others | configured (`X-Country-Code`) | configured |
 *
 * The first header present with a two-letter code wins. Cloudflare's `XX` (unknown) and `T1`
 * (Tor) read as unknown. Only trust these headers when the proxy sets them and clients cannot:
 * a client that reaches the application directly can send any value.
 *
 * ```xml
 * <module id="belisoful/prado-privacy">
 *   <resolver class="belisoful\Privacy\Consent\Region\THeaderRegionResolver" />
 * </module>
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 */
class THeaderRegionResolver extends TComponent implements IRegionResolver
{
	/** The country headers read by default, in order. */
	public const DEFAULT_COUNTRY_HEADERS = ['CF-IPCountry', 'CloudFront-Viewer-Country'];

	/** The subdivision headers read by default, in order. */
	public const DEFAULT_SUBDIVISION_HEADERS = ['cf-region-code', 'CloudFront-Viewer-Country-Region'];

	/** @var string[] the country headers */
	private array $_countryHeaders = self::DEFAULT_COUNTRY_HEADERS;

	/** @var string[] the subdivision headers */
	private array $_subdivisionHeaders = self::DEFAULT_SUBDIVISION_HEADERS;

	/**
	 * @param THttpRequest $request the request
	 * @return ?string the country, or country and subdivision (`US-CA`); null without a usable header
	 */
	public function resolveRegion(THttpRequest $request): ?string
	{
		$country = $this->readCode($request, $this->getCountryHeaders(), '/^[A-Z]{2}$/');
		if ($country === null || $country === 'XX' || $country === 'T1') {
			return null;
		}
		$subdivision = $this->readCode($request, $this->getSubdivisionHeaders(), '/^[A-Z0-9]{1,3}$/');
		return $subdivision === null ? $country : $country . '-' . $subdivision;
	}

	/**
	 * @param THttpRequest $request the request
	 * @param string[] $headers the headers, in order
	 * @param string $pattern the pattern of a usable value
	 * @return ?string the first usable value, uppercase; null when none
	 */
	protected function readCode(THttpRequest $request, array $headers, string $pattern): ?string
	{
		foreach ($headers as $header) {
			$value = \strtoupper(\trim((string) $request->getHeader($header)));
			if (\preg_match($pattern, $value)) {
				return $value;
			}
		}
		return null;
	}

	/**
	 * @return string[] the country headers, in order
	 */
	public function getCountryHeaders(): array
	{
		return $this->_countryHeaders;
	}

	/**
	 * @param mixed $value the country headers: a comma-separated string or an array
	 * @return static the resolver
	 */
	public function setCountryHeaders($value): static
	{
		$this->_countryHeaders = self::ensureList($value);
		return $this;
	}

	/**
	 * @return string[] the subdivision headers, in order
	 */
	public function getSubdivisionHeaders(): array
	{
		return $this->_subdivisionHeaders;
	}

	/**
	 * @param mixed $value the subdivision headers: a comma-separated string or an array; empty reads none
	 * @return static the resolver
	 */
	public function setSubdivisionHeaders($value): static
	{
		$this->_subdivisionHeaders = self::ensureList($value);
		return $this;
	}

	/**
	 * @param mixed $value a comma-separated string or an array
	 * @return string[] the trimmed, non-empty entries
	 */
	protected static function ensureList(mixed $value): array
	{
		$list = \is_array($value) ? $value : \explode(',', TPropertyValue::ensureString($value));
		return \array_values(\array_filter(\array_map(fn ($entry) => \trim((string) $entry), $list), fn ($entry) => $entry !== ''));
	}
}
