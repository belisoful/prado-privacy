<?php

/**
 * TRegionGroups class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Consent\Region;

use Prado\Exceptions\TInvalidDataValueException;

/**
 * TRegionGroups class.
 *
 * TRegionGroups names groups of regions for `TConsentModule::RequireConsentFor` and `OptOutFor`,
 * and matches a region against a list of groups and codes.
 *
 * | Group | Regions |
 * |---|---|
 * | `EU` | the 27 member states of the European Union |
 * | `EEA` | the EU, Iceland, Liechtenstein and Norway |
 * | `UK` | the United Kingdom (`GB`) |
 * | `CH` | Switzerland |
 * | `US-GPC` | California and the US states whose privacy laws require honoring a universal opt-out signal such as Global Privacy Control: Colorado, Connecticut, Delaware, Maryland, Minnesota, Montana, New Hampshire, New Jersey, Oregon, Texas |
 *
 * `US-GPC` follows the state laws in force when this version was released; check it against
 * current law and extend it with {@see defineGroup()} or a list of codes.
 *
 * Matching: a list entry is a group name, a country code, or a subdivision code. A subdivision
 * region (`US-CA`) matches its own code, its country (`US`), and any group holding either.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TRegionGroups
{
	/** The pattern of a region code: a country, optionally with a subdivision. */
	public const CODE_PATTERN = '/^[A-Z]{2}(-[A-Z0-9]{1,3})?$/';

	/** @var array<string, string[]> group name => region codes */
	private static array $_groups = [
		'EU' => ['AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK'],
		'EEA' => ['@EU', 'IS', 'LI', 'NO'],
		'UK' => ['GB'],
		'CH' => ['CH'],
		'US-GPC' => ['US-CA', 'US-CO', 'US-CT', 'US-DE', 'US-MD', 'US-MN', 'US-MT', 'US-NH', 'US-NJ', 'US-OR', 'US-TX'],
	];

	/**
	 * @return string[] the group names
	 */
	public static function getGroupNames(): array
	{
		return \array_keys(self::$_groups);
	}

	/**
	 * Defines or replaces a group.
	 * @param string $name the group name, uppercase
	 * @param string[] $codes the region codes, or other groups as `@NAME`
	 */
	public static function defineGroup(string $name, array $codes): void
	{
		self::$_groups[\strtoupper(\trim($name))] = \array_values($codes);
	}

	/**
	 * Expands a list of groups and codes to region codes.
	 * @param mixed $list a comma-separated string or an array of group names and region codes
	 * @throws TInvalidDataValueException when an entry is neither a group nor a region code
	 * @return string[] the region codes, uppercase, without duplicates
	 */
	public static function expand(mixed $list): array
	{
		$entries = \is_array($list) ? $list : \explode(',', (string) $list);
		$codes = [];
		foreach ($entries as $entry) {
			$entry = \strtoupper(\trim((string) $entry));
			if ($entry === '') {
				continue;
			}
			if (isset(self::$_groups[$entry])) {
				foreach (self::$_groups[$entry] as $member) {
					\array_push($codes, ...(\str_starts_with($member, '@') ? self::expand(\substr($member, 1)) : [$member]));
				}
			} elseif (\preg_match(self::CODE_PATTERN, $entry)) {
				$codes[] = $entry;
			} else {
				throw new TInvalidDataValueException('privacy_region_invalid', $entry);
			}
		}
		return \array_values(\array_unique($codes));
	}

	/**
	 * @param ?string $region the region (`DE`, `US-CA`); null for unknown
	 * @param string[] $codes region codes, as {@see expand()} returns them
	 * @return bool whether the region is in the codes, by its own code or its country
	 */
	public static function matches(?string $region, array $codes): bool
	{
		if ($region === null) {
			return false;
		}
		$region = \strtoupper($region);
		return \in_array($region, $codes, true) || \in_array(\substr($region, 0, 2), $codes, true);
	}

	/**
	 * @param mixed $region a region code in any case
	 * @return ?string the region uppercase; null when it is not a region code
	 */
	public static function normalize(mixed $region): ?string
	{
		$region = \strtoupper(\trim((string) $region));
		return \preg_match(self::CODE_PATTERN, $region) ? $region : null;
	}
}
