<?php

namespace belisoful\Privacy\Test\Unit\Consent;

use belisoful\Privacy\Consent\TConsentRecord;
use belisoful\Privacy\Consent\TSessionConsentStore;
use belisoful\Privacy\Test\Unit\PrivacyTestCase;

class TSessionConsentStoreTest extends PrivacyTestCase
{
	public function testReadingWithoutASessionCookieStartsNoSession()
	{
		$store = new TSessionConsentStore();
		$this->assertNull($store->load());
		$store->clear();
		$this->assertSame(0, $this->session->opens);
	}

	public function testSaveOpensTheSessionAndLoadReadsIt()
	{
		$store = new TSessionConsentStore();
		$record = new TConsentRecord(\str_repeat('c', 32), ['analytics' => 'granted'], 'v', 1, 2);
		$store->save($record);
		$this->assertSame(1, $this->session->opens);
		$this->assertSame($record->toArray(), $this->session->data[TSessionConsentStore::DEFAULT_SESSION_KEY]);
		$this->assertSame('granted', $store->load()->getState('analytics'));
		$store->clear();
		$this->assertSame([], $this->session->data);
	}

	public function testAReturningVisitorsSessionIsOpenedToRead()
	{
		$this->request->addCookie('FAKESESSID', 'x');
		$this->session->data['k'] = (new TConsentRecord(\str_repeat('d', 32), [], 'v', 1, 2))->toArray();
		$store = (new TSessionConsentStore())->setSessionKey('k');
		$this->assertSame('k', $store->getSessionKey());
		$this->assertSame(\str_repeat('d', 32), $store->load()->getId());
		$this->assertSame(1, $this->session->opens);
		$store->setSessionKey(' ');
		$this->assertSame(TSessionConsentStore::DEFAULT_SESSION_KEY, $store->getSessionKey());
	}
}
