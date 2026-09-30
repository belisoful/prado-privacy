<?php

namespace belisoful\Privacy\Test\Unit\Rights;

use belisoful\Privacy\Consent\TDbConsentLog;
use belisoful\Privacy\Consent\TDbConsentStore;
use belisoful\Privacy\Consent\TSessionConsentStore;
use belisoful\Privacy\Rights\TConsentPersonalDataProvider;
use belisoful\Privacy\Rights\TDataSubject;
use belisoful\Privacy\Test\Unit\PrivacyTestCase;
use belisoful\Privacy\Test\Unit\ProbeConsentModule;
use belisoful\Privacy\Test\Unit\TestDb;
use Prado\Exceptions\TConfigurationException;

class TConsentPersonalDataProviderTest extends PrivacyTestCase
{
	private ProbeConsentModule $module;

	protected function setUp(): void
	{
		parent::setUp();
		$this->module = new ProbeConsentModule();
		$this->module->setStore((new TDbConsentStore())->setDbConnection(TestDb::reset()));
		$this->module->setLogEnabled(true)->setLog((new TDbConsentLog())->setDbConnection(TestDb::connection()));
		$this->addModule('privacy', $this->module);
	}

	public function testExport()
	{
		$provider = new TConsentPersonalDataProvider();
		$this->assertSame('consent', $provider->getPersonalDataName());
		$this->login('alice');
		$this->module->acceptAll();
		$data = $provider->exportPersonalData(new TDataSubject('alice'));
		$this->assertSame(\sprintf('%032x', 1), $data['current']['id']);
		$this->assertCount(1, $data['stored']);
		$this->assertCount(1, $data['log']);
		$this->assertSame('banner', $data['log'][0]['source']);
		$bob = $provider->exportPersonalData(new TDataSubject('bob'));
		$this->assertSame(['current' => null, 'stored' => [], 'log' => []], $bob);
	}

	public function testExportWithoutADatabaseStoreOrLog()
	{
		$this->module->setStore(new TSessionConsentStore())->setLogEnabled(false);
		$this->login('alice');
		$this->module->acceptAll();
		$data = (new TConsentPersonalDataProvider())->exportPersonalData(new TDataSubject('alice'));
		$this->assertNotNull($data['current']);
		$this->assertSame([], $data['stored']);
		$this->assertSame([], $data['log']);
	}

	public function testEraseWithdrawsDeletesAndRetainsTheLog()
	{
		$this->login('alice');
		$this->module->acceptAll();
		$result = (new TConsentPersonalDataProvider())->erasePersonalData(new TDataSubject('alice'));
		$this->assertSame(1, $result->getErased(), 'the current choice; the withdrawal deleted its row');
		$this->assertSame([['count' => 2, 'reason' => TConsentPersonalDataProvider::RETAINED_REASON]], $result->getRetained(), 'the choice and the withdrawal');
		$this->assertNull($this->module->getStoredRecord());
		$this->assertSame(0, TestDb::count('privacy_consent_state'));
		$this->assertFalse((new TConsentPersonalDataProvider())->rectifyPersonalData(new TDataSubject('alice'), []));
	}

	public function testEraseForAnotherSubjectAndWithoutALog()
	{
		$this->login('alice');
		$this->module->acceptAll();
		$this->login('bob');
		$this->module->reset();
		$this->module->setLogEnabled(false);
		$result = (new TConsentPersonalDataProvider())->erasePersonalData(new TDataSubject('alice'));
		$this->assertSame(1, $result->getErased(), 'alice\'s stored row, from bob\'s request');
		$this->assertSame([], $result->getRetained());
		$this->module->setStore(new TSessionConsentStore());
		$this->assertSame(0, (new TConsentPersonalDataProvider())->erasePersonalData(new TDataSubject('alice'))->getErased());
	}

	public function testConsentModuleId()
	{
		$provider = new TConsentPersonalDataProvider();
		$this->assertSame('privacy', $provider->setConsentModuleID(' privacy ')->getConsentModuleID());
		$this->assertSame($this->module, $provider->getConsentModule());
		$this->expectException(TConfigurationException::class);
		$provider->setConsentModuleID('missing')->getConsentModule();
	}
}
