<?php

namespace belisoful\Privacy\Test\Unit\Rights;

use belisoful\Privacy\Rights\TDataSubject;
use belisoful\Privacy\Rights\TSessionPersonalDataProvider;
use belisoful\Privacy\Test\Unit\PrivacyTestCase;

class TSessionPersonalDataProviderTest extends PrivacyTestCase
{
	public function testOnlyTheCurrentUsersStartedSession()
	{
		$provider = new TSessionPersonalDataProvider();
		$this->assertSame('session', $provider->getPersonalDataName());
		$this->session->data = ['cart' => ['sku' => 3], 'user' => new \ArrayObject(), 'lang' => 'de'];
		$alice = new TDataSubject('alice');
		$this->assertSame([], $provider->exportPersonalData($alice), 'a guest');
		$this->login('alice');
		$this->assertSame([], $provider->exportPersonalData($alice), 'no started session');
		$this->session->started = true;
		$this->assertSame(['cart' => ['sku' => 3], 'user' => '[object ArrayObject]', 'lang' => 'de'], $provider->exportPersonalData($alice));
		$this->assertSame([], $provider->exportPersonalData(new TDataSubject('bob')));
		$this->assertFalse($provider->rectifyPersonalData($alice, []));
	}

	public function testErase()
	{
		$provider = new TSessionPersonalDataProvider();
		$other = $provider->erasePersonalData(new TDataSubject('bob'));
		$this->assertSame(0, $other->getErased());
		$this->assertStringContainsString('not reachable', $other->getRetained()[0]['reason']);
		$this->login('alice');
		$this->session->started = true;
		$this->assertSame(1, $provider->erasePersonalData(new TDataSubject('alice'))->getErased());
		$this->assertTrue($this->session->destroyed);
	}
}
