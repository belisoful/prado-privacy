<?php

/**
 * TDbPrivacyRequestLog class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Rights;

use belisoful\Privacy\Data\TPrivacyDbConnectionTrait;
use belisoful\Privacy\Data\TPrivacyRequestRecord;
use belisoful\Privacy\Data\TPrivacySchema;
use Prado\Data\ActiveRecord\TActiveRecordCriteria;
use Prado\TComponent;

/**
 * TDbPrivacyRequestLog class.
 *
 * TDbPrivacyRequestLog records data subject requests in `privacy_request` with `TActiveRecord`.
 * The connection comes from `ConnectionID`, or the runtime sqlite database without one
 * (`TPrivacyDbConnectionTrait`).
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 */
class TDbPrivacyRequestLog extends TComponent implements IPrivacyRequestLog
{
	use TPrivacyDbConnectionTrait;

	/** The status of a request not yet answered. */
	public const STATUS_PENDING = 'pending';

	/**
	 * Records a new request as pending.
	 * @param string $type `export`, `erase` or `rectify`
	 * @param string $subjectHash the subject's hash
	 * @param ?string $requestedBy who made the request
	 * @param int $requestedAt when, in Unix seconds
	 * @param int $dueAt when the answer is due, in Unix seconds
	 * @return int the request id
	 */
	public function open(string $type, string $subjectHash, ?string $requestedBy, int $requestedAt, int $dueAt): int
	{
		$row = $this->finder();
		$row->type = $type;
		$row->subject_hash = $subjectHash;
		$row->requested_by = $requestedBy;
		$row->requested_at = $requestedAt;
		$row->due_at = $dueAt;
		$row->status = self::STATUS_PENDING;
		$row->save();
		return (int) $row->id;
	}

	/**
	 * Records a request's outcome; an unknown id is ignored.
	 * @param int $id the request id
	 * @param string $status `completed`, `partial` or `failed`
	 * @param array $outcome the outcome per provider
	 * @param int $completedAt when, in Unix seconds
	 */
	public function complete(int $id, string $status, array $outcome, int $completedAt): void
	{
		$row = $this->finder()->findByPk($id);
		if ($row instanceof TPrivacyRequestRecord) {
			$row->status = $status;
			$row->outcome = \json_encode($outcome, JSON_THROW_ON_ERROR);
			$row->completed_at = $completedAt;
			$row->save();
		}
	}

	/**
	 * @param int $id the request id
	 * @return ?array the entry; null when there is none
	 */
	public function find(int $id): ?array
	{
		$row = $this->finder()->findByPk($id);
		return $row instanceof TPrivacyRequestRecord ? $this->toEntry($row) : null;
	}

	/**
	 * @param string $subjectHash the subject's hash
	 * @return array<int, array> the subject's requests, oldest first
	 */
	public function findBySubject(string $subjectHash): array
	{
		return $this->findEntries('subject_hash = :value', $subjectHash);
	}

	/**
	 * @return array<int, array> the pending requests, oldest first
	 */
	public function findPending(): array
	{
		return $this->findEntries('status = :value', self::STATUS_PENDING);
	}

	/**
	 * Removes requests made before a time.
	 * @param int $before a Unix time
	 * @return int the number removed
	 */
	public function purge(int $before): int
	{
		return (int) $this->finder()->deleteAll('requested_at < :before', [':before' => $before]);
	}

	/**
	 * @return TPrivacyRequestRecord a record bound to the log's connection
	 */
	protected function finder(): TPrivacyRequestRecord
	{
		return TPrivacyRequestRecord::on($this->getTableConnection(TPrivacySchema::REQUEST));
	}

	/**
	 * @param string $condition the condition, with `:value`
	 * @param string $value the value
	 * @return array<int, array> the matching entries, oldest first
	 */
	protected function findEntries(string $condition, string $value): array
	{
		$criteria = new TActiveRecordCriteria($condition, [':value' => $value]);
		$criteria->setOrdersBy(['requested_at' => 'asc', 'id' => 'asc']);
		return \array_map([$this, 'toEntry'], $this->finder()->findAll($criteria));
	}

	/**
	 * @param TPrivacyRequestRecord $row a row
	 * @return array the entry
	 */
	protected function toEntry(TPrivacyRequestRecord $row): array
	{
		return [
			'id' => (int) $row->id,
			'type' => (string) $row->type,
			'subject_hash' => (string) $row->subject_hash,
			'requested_by' => $row->requested_by,
			'requested_at' => (int) $row->requested_at,
			'due_at' => (int) $row->due_at,
			'completed_at' => $row->completed_at === null ? null : (int) $row->completed_at,
			'status' => (string) $row->status,
			'outcome' => $row->outcome === null ? null : \json_decode((string) $row->outcome, true),
		];
	}
}
