<?php

namespace belisoful\Privacy\Test\Unit\Consent;

use belisoful\Privacy\Consent\TConsentRecord;
use belisoful\Privacy\Consent\TDbConsentStore;
use belisoful\Privacy\Test\Unit\PrivacyTestCase;
use belisoful\Privacy\Test\Unit\TestDb;

class TDbConsentStoreTest extends PrivacyTestCase
{
	private function store(): TDbConsentStore
	{
		return (new TDbConsentStore())->setDbConnection(TestDb::reset());
	}

	private function record(string $id = '', string $analytics = 'granted', int $time = self::NOW): TConsentRecord
	{
		return new TConsentRecord($id ?: \str_repeat('a', 32), ['analytics' => $analytics, 'marketing' => 'denied'], 'v1', $time, $time + 1000, 'banner');
	}

	public function testSaveWritesTheRowAndTheIdCookie()
	{
		$store = $this->store();
		$store->save($this->record());
		$cookie = $this->response->lastSent();
		$this->assertSame('prado_consent_id', $cookie->getName());
		$this->assertSame(\str_repeat('a', 32), $this->app->getSecurityManager()->validateCookieData($cookie->getValue()));
		$this->assertSame(self::NOW + 1000, $cookie->getExpire());
		$this->assertSame(1, TestDb::count('privacy_consent_state'));
		$store->save($this->record('', 'denied'));
		$this->assertSame(1, TestDb::count('privacy_consent_state'), 'the row is updated in place');
		$this->assertSame('denied', $store->load()->getState('analytics'));
	}

	public function testLoadFindsTheRowOfTheCookie()
	{
		$this->store()->save($this->record());
		$this->request->addCookie('prado_consent_id', $this->app->getSecurityManager()->hashCookieData(\str_repeat('a', 32)));
		$store = (new TDbConsentStore())->setDbConnection(TestDb::connection());
		$record = $store->load();
		$this->assertSame(['analytics' => 'granted', 'marketing' => 'denied'], $record->getCategories());
		$this->assertSame('v1', $record->getVersion());
		$this->assertSame(self::NOW, $record->getTime());
		$this->assertSame(self::NOW + 1000, $record->getExpires());
		$this->assertSame('banner', $record->getSource());
	}

	public function testACookieWithoutARowIsNoChoice()
	{
		$store = $this->store();
		$this->request->addCookie('prado_consent_id', $this->app->getSecurityManager()->hashCookieData(\str_repeat('9', 32)));
		$this->assertNull($store->load());
	}

	public function testALoggedInUserGetsTheirLatestChoiceOnAnotherDevice()
	{
		$this->login('alice');
		$store = $this->store();
		$store->save($this->record(\str_repeat('1', 32), 'denied', self::NOW - 50));
		$store->save($this->record(\str_repeat('2', 32), 'granted', self::NOW));
		$this->login('bob');
		$store->save($this->record(\str_repeat('3', 32), 'denied', self::NOW + 10));
		$this->login('alice');
		$device = (new TDbConsentStore())->setDbConnection(TestDb::connection());
		$this->assertTrue($device->getRestoreForUser());
		$this->assertSame(\str_repeat('2', 32), $device->load()->getId());
		$off = (new TDbConsentStore())->setDbConnection(TestDb::connection())->setRestoreForUser('false');
		$this->assertFalse($off->getRestoreForUser());
		$this->assertNull($off->load());
	}

	public function testOnASharedDeviceAnotherUsersChoiceIsNotTheirs()
	{
		$this->login('alice');
		$store = $this->store();
		$store->save($this->record(\str_repeat('1', 32), 'denied', self::NOW - 50));
		$this->request->addCookie('prado_consent_id', $this->app->getSecurityManager()->hashCookieData(\str_repeat('1', 32)));
		$this->login('bob');
		$bob = (new TDbConsentStore())->setDbConnection(TestDb::connection());
		$this->assertNull($bob->load(), 'alice\'s choice is not bob\'s');
		$bob->save($this->record(\str_repeat('2', 32), 'granted', self::NOW));
		$this->assertSame(2, TestDb::count('privacy_consent_state'), 'bob\'s choice takes its own row');
		$alice = \belisoful\Privacy\TPrivacyHash::hash('alice', \belisoful\Privacy\TPrivacyHash::SUBJECT);
		$this->assertSame([\str_repeat('1', 32)], \array_map(fn ($r) => $r->getId(), $store->findBySubject($alice)), 'alice\'s row keeps her hash');
		$this->login('alice');
		$device = (new TDbConsentStore())->setDbConnection(TestDb::connection());
		$this->request->getCookies()->clear();
		$this->request->addCookie('prado_consent_id', $this->app->getSecurityManager()->hashCookieData(\str_repeat('2', 32)));
		$this->assertSame(\str_repeat('1', 32), $device->load()->getId(), 'back on bob\'s cookie, alice gets her own choice');
		$this->login('carol');
		$this->assertNull((new TDbConsentStore())->setDbConnection(TestDb::connection())->setRestoreForUser(false)->load());
		$this->app->setUser(new \belisoful\Privacy\Test\Unit\FakeUser());
		$this->assertSame(\str_repeat('2', 32), (new TDbConsentStore())->setDbConnection(TestDb::connection())->load()->getId(), 'a guest gets the device\'s choice');
	}

	public function testALoggedInUserAdoptsAGuestsChoice()
	{
		$store = $this->store();
		$store->save($this->record());
		$this->request->addCookie('prado_consent_id', $this->app->getSecurityManager()->hashCookieData(\str_repeat('a', 32)));
		$this->login('alice');
		$device = (new TDbConsentStore())->setDbConnection(TestDb::connection());
		$this->assertSame(\str_repeat('a', 32), $device->load()->getId());
		$device->save($this->record());
		$alice = \belisoful\Privacy\TPrivacyHash::hash('alice', \belisoful\Privacy\TPrivacyHash::SUBJECT);
		$this->assertCount(1, $device->findBySubject($alice));
	}

	public function testAGuestWithoutACookieHasNoChoice()
	{
		$this->assertNull($this->store()->load());
	}

	public function testClearDeletesTheRowAndExpiresTheCookie()
	{
		$store = $this->store();
		$store->save($this->record());
		$store->clear();
		$this->assertSame(0, TestDb::count('privacy_consent_state'));
		$this->assertSame('prado_consent_id', $this->response->removed[0]->getName());
		$this->assertNull($store->load());
		$store->clear();
		$this->assertCount(2, $this->response->removed, 'clearing without a choice only expires the cookie');
	}

	public function testClearReachesTheUsersOtherDevices()
	{
		$this->login('alice');
		$store = $this->store();
		$store->save($this->record(\str_repeat('1', 32), 'granted', self::NOW - 50));
		$store->save($this->record(\str_repeat('2', 32), 'granted', self::NOW));
		$this->login('bob');
		$store->save($this->record(\str_repeat('3', 32)));
		$this->login('alice');
		$device = (new TDbConsentStore())->setDbConnection(TestDb::connection());
		$this->request->addCookie('prado_consent_id', $this->app->getSecurityManager()->hashCookieData(\str_repeat('2', 32)));
		$device->clear();
		$this->assertSame(1, TestDb::count('privacy_consent_state'), 'bob\'s row remains');
		$this->request->getCookies()->clear();
		$this->assertNull((new TDbConsentStore())->setDbConnection(TestDb::connection())->load(), 'no other device\'s choice comes back');
	}

	public function testFindAndDeleteBySubject()
	{
		$this->login('alice');
		$store = $this->store();
		$store->save($this->record(\str_repeat('1', 32), 'denied', self::NOW - 50));
		$store->save($this->record(\str_repeat('2', 32), 'granted', self::NOW));
		$this->login('bob');
		$store->save($this->record(\str_repeat('3', 32)));
		$alice = \belisoful\Privacy\TPrivacyHash::hash('alice', \belisoful\Privacy\TPrivacyHash::SUBJECT);
		$this->assertSame([\str_repeat('2', 32), \str_repeat('1', 32)], \array_map(fn ($r) => $r->getId(), $store->findBySubject($alice)));
		TestDb::connection()->createCommand("UPDATE privacy_consent_state SET categories = 'not json' WHERE consent_id = '" . \str_repeat('1', 32) . "'")->execute();
		$this->assertCount(1, $store->findBySubject($alice), 'a row that does not parse is skipped');
		$this->assertSame(1, $store->deleteBySubject($alice, \str_repeat('2', 32)), 'a kept id stays');
		$this->assertSame(1, $store->deleteBySubject($alice));
		$this->assertSame(1, TestDb::count('privacy_consent_state'));
	}

	public function testPurgeRemovesExpiredChoices()
	{
		$store = $this->store();
		$store->save($this->record(\str_repeat('1', 32), 'granted', self::NOW - 5000));
		$store->save($this->record(\str_repeat('2', 32), 'granted', self::NOW));
		$this->assertSame(1, $store->purge(self::NOW));
		$this->assertSame(1, TestDb::count('privacy_consent_state'));
	}
}
