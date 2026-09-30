<?php

/**
 * IRegionResolver interface file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Consent\Region;

use Prado\Web\THttpRequest;

/**
 * IRegionResolver interface.
 *
 * IRegionResolver places a request in a region, so `TConsentModule` knows which law applies and
 * whether the banner is needed. A region is an ISO 3166-1 alpha-2 country code (`DE`, `GB`) or an
 * ISO 3166-2 subdivision code (`US-CA`), uppercase. Null means unknown; `TConsentModule` treats an
 * unknown region as requiring consent.
 *
 * | Resolver | Source |
 * |---|---|
 * | `TScopeAllRegionResolver` | none: every visitor is unknown (the default) |
 * | `THeaderRegionResolver` | a country header from a CDN or proxy |
 * | `TIpRegionResolver` | the IP address, through an `IIpLocator` |
 * | `TChainRegionResolver` | the first of several that answers |
 *
 * A resolver never throws for a request it cannot place; it returns null.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
interface IRegionResolver
{
	/**
	 * @param THttpRequest $request the request
	 * @return ?string the region (`DE`, `US-CA`); null when unknown
	 */
	public function resolveRegion(THttpRequest $request): ?string;
}
