<?php

namespace belisoful\Privacy\Test\Unit;

use Prado\Web\THttpCookie;
use Prado\Web\THttpCookieCollection;
use Prado\Web\THttpRequest;

/**
 * A THttpRequest over arrays: headers, cookies, the client address, the user agent and https.
 */
class FakeRequest extends THttpRequest
{
	/** @var array<string, string> header name => value */
	public array $headers = [];

	public string $ip = '203.0.113.7';

	public string $agent = 'Mozilla/5.0 (Test)';

	public bool $secure = false;

	private ?THttpCookieCollection $_fakeCookies = null;

	public function getHeaders($case = null)
	{
		$headers = [];
		foreach ($this->headers as $name => $value) {
			$headers[$case === CASE_LOWER ? \strtolower($name) : ($case === CASE_UPPER ? \strtoupper($name) : $name)] = $value;
		}
		return $headers;
	}

	public function getUserHostAddress()
	{
		return $this->ip;
	}

	public function getUserAgent()
	{
		return $this->agent;
	}

	public function getRequestURI()
	{
		return '/index.php?page=Test';
	}

	public function getIsSecureConnection()
	{
		return $this->secure;
	}

	public function getCookies()
	{
		return $this->_fakeCookies ??= new THttpCookieCollection();
	}

	public function addCookie(string $name, string $value): void
	{
		$this->getCookies()->add(new THttpCookie($name, $value));
	}
}
