<?php

namespace belisoful\Privacy\Test\Unit\Retention;

use belisoful\Privacy\Consent\Region\THttpIpLocator;
use belisoful\Privacy\Consent\Region\TIpRegionResolver;
use belisoful\Privacy\Consent\Region\TMaxMindDbLocator;
use belisoful\Privacy\Consent\TDbConsentLog;
use belisoful\Privacy\Consent\TDbConsentStore;
use belisoful\Privacy\Retention\TLegalBasis;
use belisoful\Privacy\Retention\TProcessingActivity;
use belisoful\Privacy\Retention\TProcessingRegistry;
use belisoful\Privacy\Retention\TRetentionModule;
use belisoful\Privacy\Retention\TRetentionPolicy;
use belisoful\Privacy\Rights\TPrivacyModule;
use belisoful\Privacy\Rights\TPrivacyShellAction;
use belisoful\Privacy\Test\Unit\PrivacyTestCase;
use belisoful\Privacy\Test\Unit\ProbeConsentModule;
use belisoful\Privacy\Test\Unit\TestDb;
use Prado\Exceptions\TConfigurationException;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\Prado;
use Prado\Shell\TShellApplication;
use Prado\TApplicationComponent;
use Prado\Xml\TXmlDocument;

class TProcessingRegistryTest extends PrivacyTestCase
{
	private array $listeners = [];

	protected function tearDown(): void
	{
		foreach ($this->listeners as $listener) {
			$listener->unlisten();
		}
		parent::tearDown();
	}

	public function testLegalBasis()
	{
		$this->assertSame('legal_obligation', TLegalBasis::normalize(' LegalObligation '));
		$this->assertSame('consent', TLegalBasis::normalize('CONSENT'));
		$this->assertNull(TLegalBasis::normalize('because'));
		$this->assertSame('Art. 6(1)(f)', TLegalBasis::ARTICLE[TLegalBasis::LegitimateInterests]);
	}

	public function testActivity()
	{
		$activity = new TProcessingActivity([
			'Name' => ' newsletter ', 'Purpose' => 'Sending it', 'LegalBasis' => 'Consent', 'LegalBasisNote' => 'double opt-in',
			'SubjectCategories' => 'subscribers', 'DataCategories' => 'email, date,', 'Recipients' => ['Mailgun'],
			'Transfers' => 'US: DPF', 'RetentionPolicy' => 'newsletter', 'RetentionPeriod' => 'until unsubscribed', 'SecurityMeasures' => 'TLS',
		]);
		$this->assertSame('newsletter', $activity->getName());
		$this->assertSame('Sending it', $activity->getPurpose());
		$this->assertSame('consent', $activity->getLegalBasis());
		$this->assertSame('double opt-in', $activity->getLegalBasisNote());
		$this->assertSame(['subscribers'], $activity->getSubjectCategories());
		$this->assertSame(['email', 'date'], $activity->getDataCategories());
		$this->assertSame(['Mailgun'], $activity->getRecipients());
		$this->assertSame(['US: DPF'], $activity->getTransfers());
		$this->assertSame('newsletter', $activity->getRetentionPolicy());
		$this->assertSame('until unsubscribed', $activity->getRetentionPeriod());
		$this->assertSame('TLS', $activity->getSecurityMeasures());
		$this->assertSame('consent', $activity->toArray()['LegalBasis']);
		$this->expectException(TInvalidDataValueException::class);
		$activity->setLegalBasis('because');
	}

	public function testActivitiesFromConfigurationModulesAndTheGlobalEvent()
	{
		$doc = new TXmlDocument();
		$doc->loadFromString('<module><activity Name="accounts" Purpose="Accounts" LegalBasis="contract" RetentionPeriod="until closed" /></module>');
		$registry = new TProcessingRegistry();
		$registry->init($doc);
		$consent = new ProbeConsentModule();
		$consent->setLogEnabled(true)->setLogUserAgent(true)->setStore((new TDbConsentStore())->setDbConnection(TestDb::reset()));
		$consent->setRegionResolver((new TIpRegionResolver())->setLocator((new THttpIpLocator())->setService('ipinfo')));
		$this->addModule('consent', $consent);
		$this->addModule('rights', (new TPrivacyModule())->setLogRequests(false));
		$this->listeners[] = $listener = new class () extends TApplicationComponent {
			public function fxGetProcessingActivities($sender, $param)
			{
				return new TProcessingActivity(['Name' => 'shop', 'Purpose' => 'Orders', 'LegalBasis' => 'contract']);
			}
		};
		$names = \array_keys($registry->getActivities());
		$this->assertSame(['accounts', 'consent-choice', 'consent-log', 'region-resolution', 'privacy-requests', 'shop'], $names);
		$activities = $registry->getActivities();
		$this->assertContains('ipinfo (IP geolocation provider)', $activities['region-resolution']->getRecipients());
		$this->assertStringContainsString('hashed user agent', \implode(',', $activities['consent-log']->getDataCategories()));
		$this->assertSame('consent-state', $activities['consent-choice']->getRetentionPolicy());
		$this->assertSame('not recorded', $activities['privacy-requests']->getRetentionPeriod());
		$this->assertSame(['accounts', 'shop'], \array_keys($registry->setAutoDiscover(false)->getActivities()), 'without discovery');
		$php = new TProcessingRegistry();
		$php->init(['activities' => [['Name' => 'x'], new TProcessingActivity(['Name' => 'y'])]]);
		$php->init(null);
		$this->assertFalse($php->setAutoDiscover(false)->getAutoDiscover());
		$this->assertSame(['x', 'y', 'shop'], \array_keys($php->getActivities()));
	}

	public function testAGlobalEventListAndDuplicates()
	{
		$this->listeners[] = new class () extends TApplicationComponent {
			public function fxGetProcessingActivities($sender, $param)
			{
				return [new TProcessingActivity(['Name' => 'a']), new TProcessingActivity(['Name' => 'a'])];
			}
		};
		$registry = (new TProcessingRegistry())->setAutoDiscover(false);
		$this->assertSame(['a'], \array_keys($registry->getActivities()), 'an identical declaration is listed once');
		$registry->addActivity(['Name' => 'a', 'Purpose' => 'different']);
		try {
			$registry->getActivities();
			$this->fail('duplicate');
		} catch (TConfigurationException $e) {
			$this->assertSame('privacy_activity_duplicate', $e->getErrorCode());
		}
		$this->expectException(TConfigurationException::class);
		(new TProcessingRegistry())->setAutoDiscover(false)->addActivity(['Purpose' => 'nameless'])->getActivities();
	}

	public function testIpResolverRecipients()
	{
		$this->assertSame([], (new TIpRegionResolver())->getProcessingActivities()[0]->getRecipients(), 'no locator yet');
		$this->assertSame([], (new TIpRegionResolver())->setLocator(new TMaxMindDbLocator())->getProcessingActivities()[0]->getRecipients(), 'a local database');
		$custom = (new TIpRegionResolver())->setLocator((new THttpIpLocator())->setUrl('https://geo.example.net/{ip}'));
		$this->assertSame(['geo.example.net (IP geolocation provider)'], $custom->getProcessingActivities()[0]->getRecipients());
	}

	public function testRecordFormats()
	{
		$retention = (new TRetentionModule())->setIncludeBuiltIn(false);
		$retention->addPolicy(new TRetentionPolicy('orders', 'P10Y', null, 'tax law'));
		$this->addModule('retention', $retention);
		$registry = (new TProcessingRegistry())->setAutoDiscover(false)
			->setController(' Example Ltd ')->setContact('privacy@example.test')->setDpoContact('dpo@example.test');
		$registry->addActivity(['Name' => 'orders', 'Purpose' => 'Selling | shipping', 'LegalBasis' => 'contract', 'LegalBasisNote' => 'Art. 6(1)(b)', 'DataCategories' => 'name, address', 'RetentionPolicy' => 'orders']);
		$registry->addActivity(['Name' => 'unknown-policy', 'RetentionPolicy' => 'nope', 'RetentionPeriod' => 'a while']);
		$registry->addActivity(['Name' => 'bare']);
		$this->assertSame('Example Ltd', $registry->getController());
		$this->assertSame('privacy@example.test', $registry->getContact());
		$this->assertSame('dpo@example.test', $registry->getDpoContact());
		$record = $registry->toArray();
		$this->assertSame('2026-09-29T12:00:00Z', $record['generated']);
		$this->assertSame('P10Y tax law', $record['activities']['orders']['Retention']);
		$this->assertSame('a while', $record['activities']['unknown-policy']['Retention']);
		$this->assertSame('Example Ltd', \json_decode($registry->toJson(), true)['controller']);
		$markdown = $registry->toMarkdown();
		$this->assertStringContainsString('- Controller: Example Ltd', $markdown);
		$this->assertStringContainsString('| orders | Selling \| shipping | contract (Art. 6(1)(b)): Art. 6(1)(b) | - | name, address | - | - | P10Y tax law | - |', $markdown);
		$this->assertStringContainsString('| bare | - | - | - | - | - | - | - | - |', $markdown);
	}

	public function testShellRegistration()
	{
		$shell = new TShellApplication(\dirname(__DIR__) . '/app', false);
		try {
			(new TProcessingRegistry())->init(null);
			$this->assertTrue($shell->hasShellActionClass(TPrivacyShellAction::class));
			$this->assertNull($shell->getShellActions()[TPrivacyShellAction::class]->getModule(), 'only a TPrivacyModule is set as the action\'s module');
		} finally {
			Prado::setApplication($this->app);
		}
	}
}
