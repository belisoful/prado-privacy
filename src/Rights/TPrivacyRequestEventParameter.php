<?php

/**
 * TPrivacyRequestEventParameter class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Rights;

use Prado\TEventParameter;

/**
 * TPrivacyRequestEventParameter class.
 *
 * TPrivacyRequestEventParameter carries `TPrivacyModule::onPrivacyRequest` (before the providers
 * run) and `onPrivacyRequestComplete` (after): the request `Type`, the `Subject`, the `RequestId`
 * in the request log (0 when requests are not logged), and, on completion, the `Status` and the
 * `Outcome` per provider.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TPrivacyRequestEventParameter extends TEventParameter
{
	/**
	 * @param string $_type `export`, `erase` or `rectify`
	 * @param TDataSubject $_subject the subject
	 * @param int $_requestId the request id; 0 when requests are not logged
	 * @param ?string $_status the status on completion
	 * @param array $_outcome the outcome per provider on completion
	 */
	public function __construct(
		private string $_type,
		private TDataSubject $_subject,
		private int $_requestId = 0,
		private ?string $_status = null,
		private array $_outcome = []
	) {
		parent::__construct($_subject);
	}

	/**
	 * @return string `export`, `erase` or `rectify`
	 */
	public function getType(): string
	{
		return $this->_type;
	}

	/**
	 * @return TDataSubject the subject
	 */
	public function getSubject(): TDataSubject
	{
		return $this->_subject;
	}

	/**
	 * @return int the request id; 0 when requests are not logged
	 */
	public function getRequestId(): int
	{
		return $this->_requestId;
	}

	/**
	 * @return ?string `completed`, `partial` or `failed`; null before completion
	 */
	public function getStatus(): ?string
	{
		return $this->_status;
	}

	/**
	 * @return array the outcome per provider; empty before completion
	 */
	public function getOutcome(): array
	{
		return $this->_outcome;
	}
}
