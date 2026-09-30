<?php

/**
 * TDbConsentLog class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Consent;

use belisoful\Privacy\Data\TConsentLogRecord;
use belisoful\Privacy\Data\TPrivacyDbConnectionTrait;
use belisoful\Privacy\Data\TPrivacySchema;
use Prado\Data\ActiveRecord\TActiveRecordCriteria;
use Prado\TComponent;

/**
 * TDbConsentLog class.
 *
 * TDbConsentLog appends every recorded choice to `privacy_consent_log` with `TActiveRecord`. A row
 * holds the consent id, the time, the policy version, the full decision, the source, the region,
 * whether GPC was present, and HMAC hashes of the IP address, the user name and (when
 * `TConsentModule::LogUserAgent` is on) the user agent. See `TPrivacySchema` for the columns.
 *
 * The connection comes from `ConnectionID`, or the runtime sqlite database without one
 * (`TPrivacyDbConnectionTrait`).
 *
 * ```xml
 * <module id="belisoful/prado-privacy" LogEnabled="true">
 *   <log class="belisoful\Privacy\Consent\TDbConsentLog" ConnectionID="db" />
 * </module>
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TDbConsentLog extends TComponent implements IConsentLog
{
	use TPrivacyDbConnectionTrait;

	/**
	 * Appends a row for a choice.
	 * @param TConsentRecord $record the choice
	 * @param TConsentLogContext $context the request facts
	 */
	public function record(TConsentRecord $record, TConsentLogContext $context): void
	{
		$row = TConsentLogRecord::on($this->getTableConnection(TPrivacySchema::CONSENT_LOG));
		$row->consent_id = $record->getId();
		$row->created_at = $record->getTime();
		$row->version = $record->getVersion();
		$row->categories = \json_encode($record->getCategories(), JSON_THROW_ON_ERROR);
		$row->source = $record->getSource();
		$row->region = $context->getRegion();
		$row->gpc = $context->getGpc() ? 1 : 0;
		$row->ip_hash = $context->getIpHash();
		$row->subject_hash = $context->getSubjectHash();
		$row->user_agent_hash = $context->getUserAgentHash();
		$row->save();
	}

	/**
	 * @param string $consentId the consent id
	 * @return array<int, array<string, mixed>> the entries for the id, oldest first
	 */
	public function findByConsentId(string $consentId): array
	{
		return $this->findEntries('consent_id', $consentId);
	}

	/**
	 * @param string $subjectHash the hash of a user name
	 * @return array<int, array<string, mixed>> the entries for the subject, oldest first
	 */
	public function findBySubject(string $subjectHash): array
	{
		return $this->findEntries('subject_hash', $subjectHash);
	}

	/**
	 * Removes entries created before a time.
	 * @param int $before a Unix time
	 * @return int the number of entries removed
	 */
	public function purge(int $before): int
	{
		return (int) TConsentLogRecord::on($this->getTableConnection(TPrivacySchema::CONSENT_LOG))
			->deleteAll('created_at < :before', [':before' => $before]);
	}

	/**
	 * @param string $column the column to match
	 * @param string $value the value
	 * @return array<int, array<string, mixed>> the matching entries, oldest first
	 */
	protected function findEntries(string $column, string $value): array
	{
		$criteria = new TActiveRecordCriteria($column . ' = :value', [':value' => $value]);
		$criteria->setOrdersBy(['created_at' => 'asc', 'id' => 'asc']);
		$entries = [];
		foreach (TConsentLogRecord::on($this->getTableConnection(TPrivacySchema::CONSENT_LOG))->findAll($criteria) as $row) {
			$entries[] = [
				'consent_id' => (string) $row->consent_id,
				'created_at' => (int) $row->created_at,
				'version' => (string) $row->version,
				'categories' => (array) \json_decode((string) $row->categories, true),
				'source' => (string) $row->source,
				'region' => $row->region,
				'gpc' => (bool) $row->gpc,
				'ip_hash' => $row->ip_hash,
				'subject_hash' => $row->subject_hash,
				'user_agent_hash' => $row->user_agent_hash,
			];
		}
		return $entries;
	}
}
