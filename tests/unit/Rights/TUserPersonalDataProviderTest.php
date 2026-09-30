<?php

namespace belisoful\Privacy\Test\Unit\Rights;

use belisoful\Privacy\Rights\TDataSubject;
use belisoful\Privacy\Rights\TUserPersonalDataProvider;
use belisoful\Privacy\Test\Unit\FakeUserManager;
use belisoful\Privacy\Test\Unit\PrivacyTestCase;
use Prado\Exceptions\TConfigurationException;
use Prado\Security\TAuthManager;
use Prado\Security\TUserManager;
use Prado\Xml\TXmlDocument;

class TUserPersonalDataProviderTest extends PrivacyTestCase
{
	private function configUsers(): TUserManager
	{
		$doc = new TXmlDocument();
		$doc->loadFromString('<module><user name="carol" password="x" roles="editor,admin" /></module>');
		$manager = new TUserManager();
		$manager->init($doc);
		return $manager;
	}

	public function testName()
	{
		$provider = new TUserPersonalDataProvider();
		$this->assertSame('user', $provider->getPersonalDataName());
		$this->assertSame('account', $provider->setPersonalDataName(' account ')->getPersonalDataName());
		$this->assertSame('user', $provider->setPersonalDataName('')->getPersonalDataName());
	}

	public function testUserManagerResolution()
	{
		$provider = new TUserPersonalDataProvider();
		try {
			$provider->getUserManager();
			$this->fail('none');
		} catch (TConfigurationException $e) {
			$this->assertSame('privacy_user_manager_missing', $e->getErrorCode());
		}
		$plain = new FakeUserManager('alice');
		$this->addModule('plain-users', $plain);
		$this->assertSame($plain, $provider->getUserManager(), 'the first IUserManager module');
		$auth = new TAuthManager();
		$byId = new FakeUserManager('bob');
		$this->addModule('auth-users', $byId);
		$auth->setUserManager('auth-users');
		$this->addModule('auth', $auth);
		$this->assertSame($byId, $provider->getUserManager(), 'an auth manager\'s manager, by id');
		$this->assertSame($plain, $provider->setUserManager(' plain-users ')->getUserManager());
	}

	public function testAnAuthManagerHoldingAnInstance()
	{
		$auth = new TAuthManager();
		$manager = new FakeUserManager('alice');
		$auth->setUserManager($manager);
		$this->addModule('auth', $auth);
		$this->assertSame($manager, (new TUserPersonalDataProvider())->getUserManager());
	}

	public function testExport()
	{
		$provider = (new TUserPersonalDataProvider())->setUserManager('config-users');
		$this->addModule('config-users', $this->configUsers());
		$data = $provider->exportPersonalData(new TDataSubject('carol'));
		$this->assertSame('carol', $data['name']);
		$this->assertSame(['editor', 'admin'], $data['roles']);
		$this->assertSame('carol', $data['state']['Name']);
		$provider->attachEventHandler('onExportUser', function ($sender, $param) {
			$param->setData($param->getData() + ['newsletter' => true]);
		});
		$this->assertTrue($provider->exportPersonalData(new TDataSubject('carol'))['newsletter']);
		$this->assertSame([], $provider->exportPersonalData(new TDataSubject('nobody')));
		$this->addModule('fake-users', new FakeUserManager('dave'));
		$fake = (new TUserPersonalDataProvider())->setUserManager('fake-users');
		$this->assertSame([], $fake->exportPersonalData(new TDataSubject('dave'))['state'], 'a user whose saved form is not TUser state');
	}

	public function testErase()
	{
		$this->addModule('config-users', $this->configUsers());
		$this->addModule('fake-users', new FakeUserManager('dave'));
		$config = (new TUserPersonalDataProvider())->setUserManager('config-users');
		$this->assertSame([['count' => 1, 'reason' => 'the user is defined in the application configuration (TUserManager)']], $config->erasePersonalData(new TDataSubject('carol'))->getRetained());
		$this->assertSame(0, $config->erasePersonalData(new TDataSubject('nobody'))->getErased());
		$provider = (new TUserPersonalDataProvider())->setUserManager('fake-users');
		$this->assertSame('no onEraseUser handler deletes application users', $provider->erasePersonalData(new TDataSubject('dave'))->getRetained()[0]['reason']);
		$provider->attachEventHandler('onEraseUser', function ($sender, $param) {
			$param->getResult()->addErased(1);
		});
		$this->assertSame(1, $provider->erasePersonalData(new TDataSubject('dave'))->getErased());
	}

	public function testRectify()
	{
		$provider = (new TUserPersonalDataProvider())->setUserManager('fake-users');
		$this->addModule('fake-users', new FakeUserManager('dave'));
		$this->assertFalse($provider->rectifyPersonalData(new TDataSubject('dave'), ['email' => 'x']));
		$provider->attachEventHandler('onRectifyUser', function ($sender, $param) {
			$param->setApplied($param->getChanges() === ['email' => 'x']);
		});
		$this->assertTrue($provider->rectifyPersonalData(new TDataSubject('dave'), ['email' => 'x']));
	}
}
