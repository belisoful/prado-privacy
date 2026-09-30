<?php

/**
 * TConsentStateRecord class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Data;

/**
 * TConsentStateRecord class.
 *
 * TConsentStateRecord is one row of `privacy_consent_state`, a visitor's current choice kept by
 * `TDbConsentStore`. `categories` holds JSON (category → `granted` or `denied`).
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 */
class TConsentStateRecord extends TPrivacyActiveRecord
{
	public const TABLE = TPrivacySchema::CONSENT_STATE;

	/** @var string the consent id */
	public $consent_id;

	/** @var ?string the hash of the user name */
	public $subject_hash;

	/** @var string the decision, JSON */
	public $categories;

	/** @var string the policy version */
	public $version;

	/** @var string where the choice came from */
	public $source;

	/** @var int when the choice was made */
	public $updated_at;

	/** @var int when the choice expires */
	public $expires_at;
}
