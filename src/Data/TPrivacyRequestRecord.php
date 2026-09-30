<?php

/**
 * TPrivacyRequestRecord class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Data;

/**
 * TPrivacyRequestRecord class.
 *
 * TPrivacyRequestRecord is one row of `privacy_request`, a data subject request recorded by
 * `TDbPrivacyRequestLog`. The subject is a hash, so the record survives the subject's erasure.
 * `outcome` holds JSON: per provider, the counts erased and retained with reasons, and errors.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 */
class TPrivacyRequestRecord extends TPrivacyActiveRecord
{
	public const TABLE = TPrivacySchema::REQUEST;

	/** @var ?int the request id */
	public $id;

	/** @var string `export`, `erase` or `rectify` */
	public $type;

	/** @var string the hash of the subject's name */
	public $subject_hash;

	/** @var ?string who made the request: an operator's name, or `self` */
	public $requested_by;

	/** @var int when the request was made */
	public $requested_at;

	/** @var int when the answer is due */
	public $due_at;

	/** @var ?int when the request was completed */
	public $completed_at;

	/** @var string `pending`, `completed`, `partial` or `failed` */
	public $status;

	/** @var ?string the outcome, JSON */
	public $outcome;
}
