<?php

/**
 * TChainRegionResolver class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Consent\Region;

use belisoful\Privacy\TConfiguredComponentTrait;
use Prado\TComponent;
use Prado\Web\THttpRequest;

/**
 * TChainRegionResolver class.
 *
 * TChainRegionResolver asks several resolvers in order and returns the first region one gives.
 * A typical chain reads a CDN header first (free), then a local database, then an API.
 *
 * `Resolvers` takes module ids (a comma-separated string), or in PHP configuration an array of
 * resolver instances, configuration arrays (`['class' => …, …]`) and module ids.
 *
 * ```xml
 * <module id="geo-header" class="belisoful\Privacy\Consent\Region\THeaderRegionResolver" />
 * <module id="geo-ip" class="belisoful\Privacy\Consent\Region\TIpRegionResolver" Locator="…" />
 * <module id="belisoful/prado-privacy">
 *   <resolver class="belisoful\Privacy\Consent\Region\TChainRegionResolver" Resolvers="geo-header, geo-ip" />
 * </module>
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TChainRegionResolver extends TComponent implements IRegionResolver
{
	use TConfiguredComponentTrait;

	/** @var array<int, mixed> the resolvers, or their configuration until first use */
	private array $_resolvers = [];

	/**
	 * @param THttpRequest $request the request
	 * @return ?string the first region a resolver gives; null when none does
	 */
	public function resolveRegion(THttpRequest $request): ?string
	{
		foreach ($this->getResolvers() as $resolver) {
			if (($region = $resolver->resolveRegion($request)) !== null) {
				return $region;
			}
		}
		return null;
	}

	/**
	 * @throws \Prado\Exceptions\TConfigurationException when an entry is not a resolver
	 * @return IRegionResolver[] the resolvers, in order
	 */
	public function getResolvers(): array
	{
		foreach ($this->_resolvers as $index => $resolver) {
			$this->_resolvers[$index] = $this->resolveConfiguredComponent($resolver, IRegionResolver::class);
		}
		return $this->_resolvers;
	}

	/**
	 * @param mixed $value module ids (comma-separated), or an array of resolvers, configuration arrays and module ids
	 * @return static the resolver
	 */
	public function setResolvers($value): static
	{
		$list = \is_array($value) ? $value : \explode(',', (string) $value);
		$this->_resolvers = \array_values(\array_filter($list, fn ($entry) => !\is_string($entry) || \trim($entry) !== ''));
		return $this;
	}

	/**
	 * Appends a resolver.
	 * @param mixed $resolver a resolver, a configuration array, or a module id
	 * @return static the resolver
	 */
	public function addResolver(mixed $resolver): static
	{
		$this->_resolvers[] = $resolver;
		return $this;
	}
}
