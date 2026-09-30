<?php

namespace belisoful\Privacy\Test\Unit;

use Prado\Web\THttpCookie;
use Prado\Web\THttpResponse;

/**
 * A THttpResponse that records the cookies it would send and remove.
 */
class RecordingResponse extends THttpResponse
{
	/** @var THttpCookie[] */
	public array $sent = [];

	/** @var THttpCookie[] */
	public array $removed = [];

	public function addCookie($cookie)
	{
		$this->sent[] = $cookie;
	}

	public function removeCookie($cookie)
	{
		$this->removed[] = $cookie;
	}

	public function lastSent(): ?THttpCookie
	{
		return $this->sent === [] ? null : $this->sent[\count($this->sent) - 1];
	}
}
