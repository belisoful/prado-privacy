<?php

namespace belisoful\Privacy\Test\Unit;

use belisoful\Privacy\TPrivacyHash;

class TPrivacyHashTest extends PrivacyTestCase
{
	public function testHashIsKeyedAndSeparatedByPurpose()
	{
		$ip = TPrivacyHash::hash('203.0.113.7', TPrivacyHash::IP);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $ip);
		$this->assertSame($ip, TPrivacyHash::hash('203.0.113.7', TPrivacyHash::IP));
		$this->assertNotSame($ip, TPrivacyHash::hash('203.0.113.7', TPrivacyHash::SUBJECT));
		$this->assertNotSame($ip, \hash('sha256', '203.0.113.7'));
		$this->app->getSecurityManager()->setValidationKey('another-key');
		$this->assertNotSame($ip, TPrivacyHash::hash('203.0.113.7', TPrivacyHash::IP));
		$this->assertSame(\hash_hmac('sha256', "\0x", 'another-key'), TPrivacyHash::hash('x'));
	}
}
