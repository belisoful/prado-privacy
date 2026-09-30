<?php

/**
 * TScopeAllRegionResolver class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Consent\Region;

use Prado\TComponent;
use Prado\Web\THttpRequest;

/**
 * TScopeAllRegionResolver class.
 *
 * TScopeAllRegionResolver is `TConsentModule`'s default resolver. It places no request, so every
 * visitor's region is unknown and every visitor is asked for consent: the strictest reading,
 * right for a site that serves the EU, the UK or Switzerland and does not locate its visitors.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 */
class TScopeAllRegionResolver extends TComponent implements IRegionResolver
{
	/**
	 * @param THttpRequest $request the request
	 * @return ?string null, for every request
	 */
	public function resolveRegion(THttpRequest $request): ?string
	{
		return null;
	}
}
