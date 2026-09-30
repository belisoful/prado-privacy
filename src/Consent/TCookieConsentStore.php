<?php

/**
 * TCookieConsentStore class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Consent;

use Prado\Exceptions\TInvalidDataValueException;
use Prado\Prado;
use Prado\TComponent;
use Prado\TPropertyValue;
use Prado\Util\Log\TLogger;
use Prado\Web\THttpCookie;
use Prado\Web\THttpCookieSameSite;

/**
 * TCookieConsentStore class.
 *
 * TCookieConsentStore keeps the visitor's choice in one cookie, `prado_consent` by default. The
 * value is the record's compact JSON form, signed with `TSecurityManager::hashData()` whether or
 * not the application validates cookies, so a forged or altered cookie reads as no choice.
 *
 * | Attribute | Value |
 * |---|---|
 * | Expires | the record's expiry (`TConsentModule::Expires` days after the choice) |
 * | Path | `/` (`Path`) |
 * | SameSite | `Lax` |
 * | HttpOnly | on (`HttpOnly`); turn it off when page scripts read the choice |
 * | Secure | on for an https request (`Secure` forces it on or off) |
 *
 * The cookie stores a choice the visitor asked to be remembered, so it is strictly necessary
 * storage (ePrivacy Art. 5(3)) and needs no consent itself.
 *
 * ```xml
 * <module id="belisoful/prado-privacy">
 *   <store class="belisoful\Privacy\Consent\TCookieConsentStore" CookieName="site_consent" />
 * </module>
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 */
class TCookieConsentStore extends TComponent implements IConsentStore
{
	/** The default cookie name. */
	public const DEFAULT_COOKIE_NAME = 'prado_consent';

	/** The characters a cookie name may hold (an RFC 6265 token). */
	public const COOKIE_NAME_PATTERN = '/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/';

	/** @var string the cookie name */
	private string $_cookieName = '';

	/** @var string the cookie path */
	private string $_path = '/';

	/** @var string the cookie domain */
	private string $_domain = '';

	/** @var bool whether scripts cannot read the cookie */
	private bool $_httpOnly = true;

	/** @var ?bool whether the cookie is sent over https only; null follows the request */
	private ?bool $_secure = null;

	/** @var null|false|TConsentRecord the record read this request; null before reading, false for none */
	private null|false|TConsentRecord $_record = null;

	/**
	 * Reads and verifies the cookie once per request.
	 * @return ?TConsentRecord the stored choice; null without a cookie, or with one that fails its signature or does not parse
	 */
	public function load(): ?TConsentRecord
	{
		if ($this->_record === null) {
			$this->_record = $this->readCookie() ?? false;
		}
		return $this->_record ?: null;
	}

	/**
	 * Sends the signed cookie for a choice.
	 * @param TConsentRecord $record the choice
	 */
	public function save(TConsentRecord $record): void
	{
		$this->writeCookie(\json_encode($record->toArray(), JSON_THROW_ON_ERROR), $record->getExpires());
		$this->setLoadedRecord($record);
	}

	/**
	 * Sends an expired cookie, so the browser forgets the choice.
	 */
	public function clear(): void
	{
		Prado::getApplication()->getResponse()->removeCookie($this->createCookie(''));
		$this->setLoadedRecord(null);
	}

	/**
	 * Builds the cookie with the store's attributes.
	 * @param string $value the cookie value
	 * @return THttpCookie the cookie
	 */
	public function createCookie(string $value): THttpCookie
	{
		$cookie = new THttpCookie($this->getCookieName(), $value);
		$cookie->setPath($this->getPath());
		if ($this->getDomain() !== '') {
			$cookie->setDomain($this->getDomain());
		}
		$cookie->setHttpOnly($this->getHttpOnly());
		$cookie->setSecure($this->getSecure() ?? (bool) Prado::getApplication()->getRequest()->getIsSecureConnection());
		$cookie->setSameSite(THttpCookieSameSite::Lax);
		return $cookie;
	}

	/**
	 * @return ?TConsentRecord the record in the request cookie; null when absent or not valid
	 */
	protected function readCookie(): ?TConsentRecord
	{
		$payload = $this->readCookiePayload();
		$record = $payload === null ? null : TConsentRecord::fromArray(\json_decode($payload, true));
		if ($payload !== null && $record === null) {
			$this->logUnreadable();
		}
		return $record;
	}

	/**
	 * @return ?string the verified payload of the request cookie; null when absent or its signature fails
	 */
	protected function readCookiePayload(): ?string
	{
		$app = Prado::getApplication();
		$cookie = $app->getRequest()->getCookies()->findCookieByName($this->getCookieName());
		if ($cookie === null) {
			return null;
		}
		$payload = $app->getSecurityManager()->validateData((string) $cookie->getValue());
		if ($payload === false) {
			$this->logUnreadable();
			return null;
		}
		return $payload;
	}

	/**
	 * Sends the cookie with a signed payload.
	 * @param string $payload the payload
	 * @param int $expires the expiry, in Unix seconds
	 */
	protected function writeCookie(string $payload, int $expires): void
	{
		$app = Prado::getApplication();
		$cookie = $this->createCookie($app->getSecurityManager()->hashData($payload));
		$cookie->setExpire($expires);
		$app->getResponse()->getCookies()->add($cookie);
	}

	/**
	 * Sets the record {@see load()} returns for the rest of the request.
	 * @param ?TConsentRecord $record the record; null for none
	 */
	protected function setLoadedRecord(?TConsentRecord $record): void
	{
		$this->_record = $record ?? false;
	}

	/**
	 * Logs a cookie that cannot be read.
	 */
	protected function logUnreadable(): void
	{
		Prado::log('The consent cookie "' . $this->getCookieName() . '" failed its signature or does not parse; the visitor is treated as undecided.', TLogger::WARNING, static::class);
	}

	/**
	 * @return string the cookie name
	 */
	public function getCookieName(): string
	{
		return $this->_cookieName !== '' ? $this->_cookieName : static::DEFAULT_COOKIE_NAME;
	}

	/**
	 * @param mixed $value the cookie name; empty restores the default
	 * @throws TInvalidDataValueException when the name holds a character a cookie name cannot
	 * @return static the store
	 */
	public function setCookieName($value): static
	{
		$value = \trim(TPropertyValue::ensureString($value));
		if ($value !== '' && !\preg_match(self::COOKIE_NAME_PATTERN, $value)) {
			throw new TInvalidDataValueException('privacy_cookie_name_invalid', $value);
		}
		$this->_cookieName = $value;
		$this->_record = null;
		return $this;
	}

	/**
	 * @return string the cookie path
	 */
	public function getPath(): string
	{
		return $this->_path;
	}

	/**
	 * @param mixed $value the cookie path; empty restores `/`
	 * @return static the store
	 */
	public function setPath($value): static
	{
		$this->_path = \trim(TPropertyValue::ensureString($value)) ?: '/';
		return $this;
	}

	/**
	 * @return string the cookie domain; empty for the request host
	 */
	public function getDomain(): string
	{
		return $this->_domain;
	}

	/**
	 * @param mixed $value the cookie domain; empty for the request host
	 * @return static the store
	 */
	public function setDomain($value): static
	{
		$this->_domain = \trim(TPropertyValue::ensureString($value));
		return $this;
	}

	/**
	 * @return bool whether scripts cannot read the cookie
	 */
	public function getHttpOnly(): bool
	{
		return $this->_httpOnly;
	}

	/**
	 * @param mixed $value whether scripts cannot read the cookie
	 * @return static the store
	 */
	public function setHttpOnly($value): static
	{
		$this->_httpOnly = TPropertyValue::ensureBoolean($value);
		return $this;
	}

	/**
	 * @return ?bool whether the cookie is sent over https only; null follows the request
	 */
	public function getSecure(): ?bool
	{
		return $this->_secure;
	}

	/**
	 * @param mixed $value whether the cookie is sent over https only; null or empty follows the request
	 * @return static the store
	 */
	public function setSecure($value): static
	{
		$this->_secure = ($value === null || $value === '') ? null : TPropertyValue::ensureBoolean($value);
		return $this;
	}
}
