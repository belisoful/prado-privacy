<?php

/**
 * TDataSubject class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Rights;

use belisoful\Privacy\TPrivacyHash;
use Prado\Prado;
use Prado\Security\IUser;
use Prado\TComponent;

/**
 * TDataSubject class.
 *
 * TDataSubject identifies the person a request is about: `Name` (the application's user name,
 * the key most providers use), an optional `Email`, and any other `Identifiers` a provider needs
 * (a customer number, a phone number). {@see getHash()} is the subject's HMAC, the form in which
 * logs refer to a person.
 *
 * The application verifies that the requester is the subject (or authorized by them) before it
 * builds a TDataSubject and hands it to `TPrivacyModule`.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TDataSubject extends TComponent
{
	/**
	 * @param string $_name the user name
	 * @param ?string $_email the email address
	 * @param array<string, string> $_identifiers other identifiers, by kind
	 */
	public function __construct(private string $_name, private ?string $_email = null, private array $_identifiers = [])
	{
		parent::__construct();
	}

	/**
	 * @param IUser $user a user
	 * @return TDataSubject the subject the user's name identifies
	 */
	public static function fromUser(IUser $user): TDataSubject
	{
		return new self((string) $user->getName());
	}

	/**
	 * @return string the user name
	 */
	public function getName(): string
	{
		return $this->_name;
	}

	/**
	 * @return ?string the email address
	 */
	public function getEmail(): ?string
	{
		return $this->_email;
	}

	/**
	 * @return array<string, string> other identifiers, by kind
	 */
	public function getIdentifiers(): array
	{
		return $this->_identifiers;
	}

	/**
	 * @param string $kind the kind of identifier
	 * @return ?string the identifier; null when the subject has none of that kind
	 */
	public function getIdentifier(string $kind): ?string
	{
		return $this->_identifiers[$kind] ?? null;
	}

	/**
	 * @return string the HMAC of the user name, as the consent log and the request log hold it
	 */
	public function getHash(): string
	{
		return TPrivacyHash::hash($this->_name, TPrivacyHash::SUBJECT);
	}

	/**
	 * @return bool whether the subject is the request's logged-in user
	 */
	public function getIsCurrentUser(): bool
	{
		$user = Prado::getApplication()->getUser();
		return $user !== null && !$user->getIsGuest() && (string) $user->getName() === $this->_name;
	}
}
