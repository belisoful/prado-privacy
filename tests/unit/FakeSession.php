<?php

namespace belisoful\Privacy\Test\Unit;

use Prado\Web\THttpSession;

/**
 * A THttpSession over an array; open() only marks it started.
 */
class FakeSession extends THttpSession
{
	public array $data = [];

	public bool $started = false;

	public int $opens = 0;

	public bool $destroyed = false;

	public function open()
	{
		$this->started = true;
		$this->opens++;
	}

	public function destroy()
	{
		$this->destroyed = true;
		$this->data = [];
	}

	public function getIsStarted()
	{
		return $this->started;
	}

	public function getSessionName()
	{
		return 'FAKESESSID';
	}

	public function getSessionID()
	{
		return 'fake-session-id';
	}

	public function itemAt($key)
	{
		return $this->data[$key] ?? null;
	}

	public function add($key, $value)
	{
		$this->data[$key] = $value;
	}

	public function remove($key)
	{
		$value = $this->data[$key] ?? null;
		unset($this->data[$key]);
		return $value;
	}

	public function toArray()
	{
		return $this->data;
	}
}
