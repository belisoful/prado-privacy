<?php

namespace belisoful\Privacy\Test\Unit\Consent;

use belisoful\Privacy\Consent\TConsentRecord;
use belisoful\Privacy\Consent\TCookieConsentStore;
use belisoful\Privacy\Test\Unit\PrivacyTestCase;
use Prado\Exceptions\TInvalidDataValueException;

class TCookieConsentStoreTest extends PrivacyTestCase
{
	private function record(): TConsentRecord
	{
		return new TConsentRecord(\str_repeat('b', 32), ['analytics' => 'granted', 'marketing' => 'denied'], 'v1', self::NOW, self::NOW + 86400, 'banner');
	}

	public function testSaveSendsASignedCookieWithTheAttributes()
	{
		$store = new TCookieConsentStore();
		$store->save($this->record());
		$cookie = $this->response->lastSent();
		$this->assertSame('prado_consent', $cookie->getName());
		$this->assertSame('/', $cookie->getPath());
		$this->assertTrue($cookie->getHttpOnly());
		$this->assertFalse($cookie->getSecure());
		$this->assertSame('Lax', $cookie->getSameSite());
		$this->assertSame(self::NOW + 86400, $cookie->getExpire());
		$payload = $this->app->getSecurityManager()->validateData($cookie->getValue());
		$this->assertSame($this->record()->toArray(), \json_decode($payload, true));
		$this->assertSame('granted', $store->load()->getState('analytics'));
	}

	public function testLoadReadsAndVerifiesTheRequestCookieOnce()
	{
		$value = $this->app->getSecurityManager()->hashData(\json_encode($this->record()->toArray()));
		$this->request->addCookie('prado_consent', $value);
		$store = new TCookieConsentStore();
		$record = $store->load();
		$this->assertSame(\str_repeat('b', 32), $record->getId());
		$this->request->getCookies()->clear();
		$this->assertSame($record, $store->load(), 'read once per request');
	}

	public function testLoadWithoutACookieIsNull()
	{
		$this->assertNull((new TCookieConsentStore())->load());
	}

	public function testATamperedCookieReadsAsNoChoice()
	{
		$value = $this->app->getSecurityManager()->hashData(\json_encode($this->record()->toArray()));
		$this->request->addCookie('prado_consent', \str_replace('"marketing":0', '"marketing":1', $value));
		$this->assertNull((new TCookieConsentStore())->load());
	}

	public function testASignedButMalformedCookieReadsAsNoChoice()
	{
		$this->request->addCookie('prado_consent', $this->app->getSecurityManager()->hashData('{"id":"nope"}'));
		$this->assertNull((new TCookieConsentStore())->load());
	}

	public function testClearExpiresTheCookie()
	{
		$store = new TCookieConsentStore();
		$store->save($this->record());
		$store->clear();
		$this->assertSame('prado_consent', $this->response->removed[0]->getName());
		$this->assertNull($store->load());
	}

	public function testCookieAttributes()
	{
		$store = new TCookieConsentStore();
		$this->assertSame($store, $store->setCookieName(' site_consent ')->setPath('')->setDomain(' example.com ')->setHttpOnly('false')->setSecure('true'));
		$this->assertSame('site_consent', $store->getCookieName());
		$this->assertSame('/', $store->getPath());
		$this->assertSame('example.com', $store->getDomain());
		$this->assertFalse($store->getHttpOnly());
		$this->assertTrue($store->getSecure());
		$cookie = $store->createCookie('x');
		$this->assertSame('example.com', $cookie->getDomain());
		$this->assertTrue($cookie->getSecure());
		$this->assertFalse($cookie->getHttpOnly());
		$store->setSecure('');
		$this->assertNull($store->getSecure());
		$this->request->secure = true;
		$this->assertTrue($store->createCookie('x')->getSecure());
		$store->setSecure(null)->setCookieName('');
		$this->assertSame(TCookieConsentStore::DEFAULT_COOKIE_NAME, $store->getCookieName());
	}

	public function testCookieNameIsValidated()
	{
		$this->expectException(TInvalidDataValueException::class);
		(new TCookieConsentStore())->setCookieName('bad name;');
	}
}
