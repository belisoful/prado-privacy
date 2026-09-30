<?php

/**
 * IConsentLog interface file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Consent;

use belisoful\Privacy\Retention\IRetentionPurger;

/**
 * IConsentLog interface.
 *
 * IConsentLog appends every recorded choice, so a controller can demonstrate consent (GDPR Art.
 * 7(1)). Entries are never updated. An entry is an array with the keys `consent_id`,
 * `created_at`, `version`, `categories` (category → granted|denied), `source`, `region`, `gpc`,
 * `ip_hash`, `subject_hash` and `user_agent_hash`. As an `IRetentionPurger`, a log removes the
 * entries created before a cutoff.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
interface IConsentLog extends IRetentionPurger
{
	/**
	 * Appends a choice.
	 * @param TConsentRecord $record the choice
	 * @param TConsentLogContext $context the request facts
	 */
	public function record(TConsentRecord $record, TConsentLogContext $context): void;

	/**
	 * @param string $consentId the consent id
	 * @return array<int, array<string, mixed>> the entries for the id, oldest first
	 */
	public function findByConsentId(string $consentId): array;

	/**
	 * @param string $subjectHash the hash of a user name
	 * @return array<int, array<string, mixed>> the entries for the subject, oldest first
	 */
	public function findBySubject(string $subjectHash): array;
}
