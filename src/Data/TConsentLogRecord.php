<?php

/**
 * TConsentLogRecord class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Data;

/**
 * TConsentLogRecord class.
 *
 * TConsentLogRecord is one row of `privacy_consent_log`, the append-only consent log written by
 * `TDbConsentLog`. `categories` holds JSON (category → `granted` or `denied`).
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TConsentLogRecord extends TPrivacyActiveRecord
{
	public const TABLE = TPrivacySchema::CONSENT_LOG;

	/** @var ?int the row id */
	public $id;

	/** @var string the consent id */
	public $consent_id;

	/** @var int when the choice was made */
	public $created_at;

	/** @var string the policy version */
	public $version;

	/** @var string the decision, JSON */
	public $categories;

	/** @var string where the choice came from */
	public $source;

	/** @var ?string the resolved region */
	public $region;

	/** @var int 1 when the request carried a GPC signal */
	public $gpc = 0;

	/** @var ?string the hash of the IP address */
	public $ip_hash;

	/** @var ?string the hash of the user name */
	public $subject_hash;

	/** @var ?string the hash of the user agent */
	public $user_agent_hash;
}
