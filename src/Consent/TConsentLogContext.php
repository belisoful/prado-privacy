<?php

/**
 * TConsentLogContext class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Consent;

use Prado\TComponent;

/**
 * TConsentLogContext class.
 *
 * TConsentLogContext holds the request facts logged beside a choice. Identifiers are HMAC-SHA256
 * hashes keyed with the application's validation key, so the log proves which request made a
 * choice without holding an IP address, a user name or a user agent in clear.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 */
class TConsentLogContext extends TComponent
{
	/**
	 * @param ?string $_ipHash the hash of the visitor's IP address
	 * @param ?string $_region the resolved region
	 * @param bool $_gpc whether the request carried a Global Privacy Control signal
	 * @param ?string $_subjectHash the hash of the logged-in user's name
	 * @param ?string $_userAgentHash the hash of the user agent
	 */
	public function __construct(
		private ?string $_ipHash = null,
		private ?string $_region = null,
		private bool $_gpc = false,
		private ?string $_subjectHash = null,
		private ?string $_userAgentHash = null
	) {
		parent::__construct();
	}

	/**
	 * @return ?string the hash of the visitor's IP address
	 */
	public function getIpHash(): ?string
	{
		return $this->_ipHash;
	}

	/**
	 * @return ?string the resolved region, `DE` or `US-CA`; null when unknown
	 */
	public function getRegion(): ?string
	{
		return $this->_region;
	}

	/**
	 * @return bool whether the request carried a Global Privacy Control signal
	 */
	public function getGpc(): bool
	{
		return $this->_gpc;
	}

	/**
	 * @return ?string the hash of the logged-in user's name; null for a guest
	 */
	public function getSubjectHash(): ?string
	{
		return $this->_subjectHash;
	}

	/**
	 * @return ?string the hash of the user agent; null unless the module logs it
	 */
	public function getUserAgentHash(): ?string
	{
		return $this->_userAgentHash;
	}
}
