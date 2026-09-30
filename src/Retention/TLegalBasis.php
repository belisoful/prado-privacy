<?php

/**
 * TLegalBasis class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Retention;

use Prado\TEnumerable;

/**
 * TLegalBasis class.
 *
 * TLegalBasis enumerates the lawful bases of processing in GDPR Art. 6(1).
 *
 * | Value | Art. 6(1) |
 * |---|---|
 * | `consent` | (a) |
 * | `contract` | (b) |
 * | `legal_obligation` | (c) |
 * | `vital_interests` | (d) |
 * | `public_task` | (e) |
 * | `legitimate_interests` | (f) |
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TLegalBasis extends TEnumerable
{
	public const Consent = 'consent';
	public const Contract = 'contract';
	public const LegalObligation = 'legal_obligation';
	public const VitalInterests = 'vital_interests';
	public const PublicTask = 'public_task';
	public const LegitimateInterests = 'legitimate_interests';

	/** Value => the point of Art. 6(1). */
	public const ARTICLE = [
		self::Consent => 'Art. 6(1)(a)',
		self::Contract => 'Art. 6(1)(b)',
		self::LegalObligation => 'Art. 6(1)(c)',
		self::VitalInterests => 'Art. 6(1)(d)',
		self::PublicTask => 'Art. 6(1)(e)',
		self::LegitimateInterests => 'Art. 6(1)(f)',
	];

	/**
	 * @param mixed $value a value (`legal_obligation`) or a constant name (`LegalObligation`), in any case
	 * @return ?string the value; null when it names no basis
	 */
	public static function normalize(mixed $value): ?string
	{
		$value = \strtolower(\trim((string) $value));
		foreach (\array_keys(self::ARTICLE) as $basis) {
			if ($value === $basis || $value === \str_replace('_', '', $basis)) {
				return $basis;
			}
		}
		return null;
	}
}
