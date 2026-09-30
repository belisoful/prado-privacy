<?php

namespace belisoful\Privacy\Test\Unit;

use Prado\Security\IUser;
use Prado\TComponent;

/**
 * An IUser with a name, roles and a guest flag.
 */
class FakeUser extends TComponent implements IUser
{
	public function __construct(public string $name = 'guest', public bool $guest = true, public array $roles = [])
	{
		parent::__construct();
	}

	public function getName()
	{
		return $this->name;
	}

	public function setName($value)
	{
		$this->name = $value;
	}

	public function getIsGuest()
	{
		return $this->guest;
	}

	public function setIsGuest($value)
	{
		$this->guest = (bool) $value;
	}

	public function getRoles()
	{
		return $this->roles;
	}

	public function setRoles($value)
	{
		$this->roles = (array) $value;
	}

	public function isInRole($role)
	{
		return \in_array($role, $this->roles, true);
	}

	public function saveToString()
	{
		return \serialize([$this->name, $this->guest, $this->roles]);
	}

	public function loadFromString($string)
	{
		[$this->name, $this->guest, $this->roles] = \unserialize($string);
		return $this;
	}
}
