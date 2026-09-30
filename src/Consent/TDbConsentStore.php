<?php

/**
 * TDbConsentStore class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Consent;

use belisoful\Privacy\Data\TConsentStateRecord;
use belisoful\Privacy\Data\TPrivacyDbConnectionTrait;
use belisoful\Privacy\Data\TPrivacySchema;
use belisoful\Privacy\Retention\IRetentionPurger;
use belisoful\Privacy\TPrivacyHash;
use Prado\Data\ActiveRecord\TActiveRecordCriteria;
use Prado\Prado;
use Prado\TPropertyValue;

/**
 * TDbConsentStore class.
 *
 * TDbConsentStore keeps the visitor's choice in a `privacy_consent_state` row. The browser holds
 * only the consent id, in the signed cookie `prado_consent_id` (the attributes of
 * `TCookieConsentStore` apply). The row also records the hash of the logged-in user's name, so
 * with `RestoreForUser` (the default) a user who logs in on another device gets the choice made
 * on the first one.
 *
 * The connection comes from `ConnectionID`, or the runtime sqlite database without one
 * (`TPrivacyDbConnectionTrait`). As an `IRetentionPurger`, the store removes the choices that
 * expired before a cutoff.
 *
 * ```xml
 * <module id="belisoful/prado-privacy">
 *   <store class="belisoful\Privacy\Consent\TDbConsentStore" ConnectionID="db" />
 * </module>
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TDbConsentStore extends TCookieConsentStore implements IRetentionPurger
{
	use TPrivacyDbConnectionTrait;

	/** The default name of the cookie holding the consent id. */
	public const DEFAULT_COOKIE_NAME = 'prado_consent_id';

	/** @var bool whether a logged-in user without a consent cookie gets their latest stored choice */
	private bool $_restoreForUser = true;

	/**
	 * Writes the choice to its row and sends the consent id cookie.
	 * @param TConsentRecord $record the choice
	 */
	public function save(TConsentRecord $record): void
	{
		$connection = $this->getTableConnection(TPrivacySchema::CONSENT_STATE);
		$row = TConsentStateRecord::on($connection)->findByPk($record->getId());
		if (!($row instanceof TConsentStateRecord)) {
			$row = TConsentStateRecord::on($connection);
		}
		$compact = $record->toArray();
		$row->consent_id = $record->getId();
		$row->subject_hash = $this->getSubjectHash();
		$row->categories = \json_encode($compact['c'], JSON_THROW_ON_ERROR);
		$row->version = $record->getVersion();
		$row->source = $record->getSource();
		$row->updated_at = $record->getTime();
		$row->expires_at = $record->getExpires();
		$row->save();
		$this->writeCookie($record->getId(), $record->getExpires());
		$this->setLoadedRecord($record);
	}

	/**
	 * Deletes the choice's row and expires the consent id cookie.
	 */
	public function clear(): void
	{
		$record = $this->load();
		if ($record !== null) {
			TConsentStateRecord::on($this->getTableConnection(TPrivacySchema::CONSENT_STATE))->deleteByPk($record->getId());
		}
		parent::clear();
	}

	/**
	 * @param string $subjectHash the hash of a user name
	 * @return TConsentRecord[] the choices stored for the subject, newest first
	 */
	public function findBySubject(string $subjectHash): array
	{
		$criteria = new TActiveRecordCriteria('subject_hash = :subject', [':subject' => $subjectHash]);
		$criteria->setOrdersBy(['updated_at' => 'desc']);
		$records = [];
		foreach (TConsentStateRecord::on($this->getTableConnection(TPrivacySchema::CONSENT_STATE))->findAll($criteria) as $row) {
			$records[] = $this->toRecord($row);
		}
		return \array_values(\array_filter($records));
	}

	/**
	 * Deletes the choices that expired before a time; the retention policy `consent-state` runs it.
	 * @param int $before a Unix time
	 * @return int the number of choices deleted
	 */
	public function purge(int $before): int
	{
		return (int) TConsentStateRecord::on($this->getTableConnection(TPrivacySchema::CONSENT_STATE))
			->deleteAll('expires_at < :before', [':before' => $before]);
	}

	/**
	 * Deletes the choices stored for a subject.
	 * @param string $subjectHash the hash of a user name
	 * @return int the number of choices deleted
	 */
	public function deleteBySubject(string $subjectHash): int
	{
		return (int) TConsentStateRecord::on($this->getTableConnection(TPrivacySchema::CONSENT_STATE))
			->deleteAll('subject_hash = :subject', [':subject' => $subjectHash]);
	}

	/**
	 * Finds the row of the cookie's consent id; without a cookie, the logged-in user's latest row when `RestoreForUser` is on.
	 * @return ?TConsentRecord the stored choice
	 */
	protected function readCookie(): ?TConsentRecord
	{
		$id = $this->readCookiePayload();
		$finder = TConsentStateRecord::on($this->getTableConnection(TPrivacySchema::CONSENT_STATE));
		if ($id !== null) {
			$row = $finder->findByPk($id);
		} elseif ($this->getRestoreForUser() && ($subject = $this->getSubjectHash()) !== null) {
			$criteria = new TActiveRecordCriteria('subject_hash = :subject', [':subject' => $subject]);
			$criteria->setOrdersBy(['updated_at' => 'desc']);
			$criteria->setLimit(1);
			$row = $finder->find($criteria);
		} else {
			return null;
		}
		return $row instanceof TConsentStateRecord ? $this->toRecord($row) : null;
	}

	/**
	 * @param TConsentStateRecord $row a row
	 * @return ?TConsentRecord the choice the row holds; null when the row does not parse
	 */
	protected function toRecord(TConsentStateRecord $row): ?TConsentRecord
	{
		return TConsentRecord::fromArray([
			'id' => (string) $row->consent_id,
			'v' => (string) $row->version,
			't' => (int) $row->updated_at,
			'e' => (int) $row->expires_at,
			's' => (string) $row->source,
			'c' => \json_decode((string) $row->categories, true),
		]);
	}

	/**
	 * @return ?string the hash of the logged-in user's name; null for a guest or without a user
	 */
	protected function getSubjectHash(): ?string
	{
		$user = Prado::getApplication()->getUser();
		return ($user === null || $user->getIsGuest()) ? null : TPrivacyHash::hash((string) $user->getName(), TPrivacyHash::SUBJECT);
	}

	/**
	 * @return bool whether a logged-in user without a consent cookie gets their latest stored choice
	 */
	public function getRestoreForUser(): bool
	{
		return $this->_restoreForUser;
	}

	/**
	 * @param mixed $value whether a logged-in user without a consent cookie gets their latest stored choice
	 * @return static the store
	 */
	public function setRestoreForUser($value): static
	{
		$this->_restoreForUser = TPropertyValue::ensureBoolean($value);
		return $this;
	}
}
