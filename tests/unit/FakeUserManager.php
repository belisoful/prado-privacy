<?php

namespace belisoful\Privacy\Test\Unit;

use Prado\Security\IUserManager;
use Prado\TModule;

/**
 * An IUserManager module over an array of FakeUsers.
 */
class FakeUserManager extends TModule implements IUserManager
{
	/** @var array<string, FakeUser> */
	public array $users = [];

	public function __construct(string ...$names)
	{
		parent::__construct();
		foreach ($names as $name) {
			$this->users[$name] = new FakeUser($name, false, ['member']);
		}
	}

	public function getGuestName()
	{
		return 'Guest';
	}

	public function getUser($username = null)
	{
		return $username === null ? new FakeUser() : ($this->users[$username] ?? null);
	}

	public function getUserFromCookie($cookie)
	{
		return null;
	}

	public function saveUserToCookie($cookie)
	{
	}

	public function validateUser($username, $password)
	{
		return false;
	}
}
