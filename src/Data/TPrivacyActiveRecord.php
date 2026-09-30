<?php

/**
 * TPrivacyActiveRecord class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Data;

use Prado\Data\ActiveRecord\TActiveRecord;
use Prado\Data\TDbConnection;

/**
 * TPrivacyActiveRecord class.
 *
 * TPrivacyActiveRecord is the base of the package's records. Each component that writes records
 * has its own connection (`TPrivacyDbConnectionTrait`), not the application's default
 * `TActiveRecordManager` connection, so a finder is made for a connection with {@see on()} and
 * every record it finds keeps that connection for `save()` and `delete()`.
 *
 * ```php
 * $rows = TConsentLogRecord::on($connection)->findAll('consent_id = ?', $id);
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 */
abstract class TPrivacyActiveRecord extends TActiveRecord
{
	/**
	 * @param array $data the column values
	 * @param ?TDbConnection $connection the connection; null uses the application's active record connection
	 */
	final public function __construct($data = [], $connection = null)
	{
		parent::__construct($data, $connection);
	}

	/**
	 * @param TDbConnection $connection the connection
	 * @param array $data the column values of a new record
	 * @return static a record (and finder) bound to the connection
	 */
	public static function on(TDbConnection $connection, array $data = []): static
	{
		return new static($data, $connection);
	}

	/**
	 * Populates a found record and binds it to this record's connection; a miss stays null.
	 * @param array $data the row
	 * @return ?TActiveRecord the record
	 */
	protected function populateObject($data)
	{
		$record = parent::populateObject($data);
		$record?->setDbConnection($this->getDbConnection());
		return $record;
	}
}
