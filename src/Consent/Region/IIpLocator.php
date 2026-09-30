<?php

/**
 * IIpLocator interface file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Consent\Region;

/**
 * IIpLocator interface.
 *
 * IIpLocator locates an IP address: a local database (`TMaxMindDbLocator`), a web API
 * (`THttpIpLocator`), or any other source an application or extension implements. It answers
 * for one address and knows nothing of requests, proxies or caching; `TIpRegionResolver` does
 * those.
 *
 * A locator never throws for an address it cannot place, or for a failed lookup; it returns
 * null, and may log the failure.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 */
interface IIpLocator
{
	/**
	 * @param string $ip an IPv4 or IPv6 address
	 * @return ?TIpLocation the location; null when the address cannot be placed
	 */
	public function locate(string $ip): ?TIpLocation;
}
