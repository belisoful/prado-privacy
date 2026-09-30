<?php

/**
 * IPrivacyRequestLog interface file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Rights;

/**
 * IPrivacyRequestLog interface.
 *
 * IPrivacyRequestLog records data subject requests and their outcomes, so a controller can show
 * that it answered each request, and when (GDPR Art. 5(2), 12(3); CCPA regulations §7101). A
 * request is opened, then completed. Entries refer to the subject by hash only.
 *
 * An entry is an array with the keys `id`, `type`, `subject_hash`, `requested_by`,
 * `requested_at`, `due_at`, `completed_at`, `status` and `outcome` (decoded).
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
interface IPrivacyRequestLog
{
	/**
	 * Records a new request as pending.
	 * @param string $type `export`, `erase` or `rectify`
	 * @param string $subjectHash the subject's hash
	 * @param ?string $requestedBy who made the request
	 * @param int $requestedAt when, in Unix seconds
	 * @param int $dueAt when the answer is due, in Unix seconds
	 * @return int the request id
	 */
	public function open(string $type, string $subjectHash, ?string $requestedBy, int $requestedAt, int $dueAt): int;

	/**
	 * Records a request's outcome.
	 * @param int $id the request id
	 * @param string $status `completed`, `partial` or `failed`
	 * @param array $outcome the outcome per provider, without personal data
	 * @param int $completedAt when, in Unix seconds
	 */
	public function complete(int $id, string $status, array $outcome, int $completedAt): void;

	/**
	 * @param int $id the request id
	 * @return ?array the entry; null when there is none
	 */
	public function find(int $id): ?array;

	/**
	 * @param string $subjectHash the subject's hash
	 * @return array<int, array> the subject's requests, oldest first
	 */
	public function findBySubject(string $subjectHash): array;

	/**
	 * @return array<int, array> the pending requests, oldest first
	 */
	public function findPending(): array;

	/**
	 * Removes requests made before a time.
	 * @param int $before a Unix time
	 * @return int the number removed
	 */
	public function purge(int $before): int;
}
