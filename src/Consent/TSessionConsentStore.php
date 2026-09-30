<?php

/**
 * TSessionConsentStore class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Consent;

use Prado\Prado;
use Prado\TComponent;
use Prado\TPropertyValue;

/**
 * TSessionConsentStore class.
 *
 * TSessionConsentStore keeps the visitor's choice in the application session under `SessionKey`.
 * The choice lasts as long as the session. Reading never starts a session: without the session
 * cookie, the store reports no choice. Saving opens the session.
 *
 * ```xml
 * <module id="belisoful/prado-privacy">
 *   <store class="belisoful\Privacy\Consent\TSessionConsentStore" />
 * </module>
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TSessionConsentStore extends TComponent implements IConsentStore
{
	/** The default session key. */
	public const DEFAULT_SESSION_KEY = 'prado_privacy_consent';

	/** @var string the session key */
	private string $_sessionKey = self::DEFAULT_SESSION_KEY;

	/**
	 * @return ?TConsentRecord the choice in the session; null without a session or a choice
	 */
	public function load(): ?TConsentRecord
	{
		$session = $this->openSession(false);
		return $session === null ? null : TConsentRecord::fromArray($session->itemAt($this->getSessionKey()));
	}

	/**
	 * Stores the choice in the session, opening it.
	 * @param TConsentRecord $record the choice
	 */
	public function save(TConsentRecord $record): void
	{
		$this->openSession(true)->add($this->getSessionKey(), $record->toArray());
	}

	/**
	 * Removes the choice from the session, when there is one.
	 */
	public function clear(): void
	{
		$session = $this->openSession(false);
		if ($session !== null) {
			$session->remove($this->getSessionKey());
		}
	}

	/**
	 * Opens the application session: for a write always, for a read only when the request carries the session cookie.
	 * @param bool $forWrite whether the caller writes
	 * @return ?\Prado\Web\THttpSession the session; null for a read without a session
	 */
	protected function openSession(bool $forWrite): ?\Prado\Web\THttpSession
	{
		$app = Prado::getApplication();
		$session = $app->getSession();
		if (!$session->getIsStarted()) {
			if (!$forWrite && $app->getRequest()->getCookies()->findCookieByName($session->getSessionName()) === null) {
				return null;
			}
			$session->open();
		}
		return $session;
	}

	/**
	 * @return string the session key
	 */
	public function getSessionKey(): string
	{
		return $this->_sessionKey;
	}

	/**
	 * @param mixed $value the session key; empty restores the default
	 * @return static the store
	 */
	public function setSessionKey($value): static
	{
		$this->_sessionKey = \trim(TPropertyValue::ensureString($value)) ?: self::DEFAULT_SESSION_KEY;
		return $this;
	}
}
