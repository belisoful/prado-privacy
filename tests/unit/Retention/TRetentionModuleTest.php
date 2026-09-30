<?php

namespace belisoful\Privacy\Test\Unit\Retention;

use belisoful\Privacy\Consent\TDbConsentLog;
use belisoful\Privacy\Consent\TDbConsentStore;
use belisoful\Privacy\Consent\TSessionConsentStore;
use belisoful\Privacy\Retention\IRetentionPurger;
use belisoful\Privacy\Retention\TMethodRetentionPurger;
use belisoful\Privacy\Retention\TRetentionModule;
use belisoful\Privacy\Retention\TRetentionPolicy;
use belisoful\Privacy\Rights\TDbPrivacyRequestLog;
use belisoful\Privacy\Rights\TPrivacyModule;
use belisoful\Privacy\Rights\TPrivacyShellAction;
use belisoful\Privacy\Test\Unit\PrivacyTestCase;
use belisoful\Privacy\Test\Unit\ProbeConsentModule;
use belisoful\Privacy\Test\Unit\TestDb;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\Prado;
use Prado\Shell\TShellApplication;
use Prado\TComponent;
use Prado\Util\Cron\TCronTaskInfo;
use Prado\Xml\TXmlDocument;

class TRetentionModuleTest extends PrivacyTestCase
{
	public function testConfiguration()
	{
		$doc = new TXmlDocument();
		$doc->loadFromString('<module>
			<policy Name="uploads" Period="P1Y" Description="kept a year"><purger class="belisoful\Privacy\Retention\TMethodRetentionPurger" Method="files->purge" /></policy>
			<policy Name="bare" Period="P1D" />
		</module>');
		$module = new TRetentionModule();
		$module->init($doc);
		$this->assertSame(['uploads', 'bare'], \array_keys($module->getPolicies()));
		$this->assertSame('files->purge', $module->getPolicy('uploads')->getPurger()->getMethod());
		$this->assertNull($module->getPolicy('none'));
		$php = new TRetentionModule();
		$php->init(['policies' => [['Name' => 'x', 'Period' => 'P2D', 'Purger' => new CountingPurger(0)], new TRetentionPolicy('y', 'P3D')]]);
		$this->assertSame(['x', 'y'], \array_keys($php->getPolicies()));
		$php->init(null);
		$this->expectException(TInvalidDataValueException::class);
		$php->addPolicy(['Period' => 'P1D']);
	}

	public function testBuiltInPoliciesFollowTheModules()
	{
		$module = new TRetentionModule();
		$this->assertTrue($module->getIncludeBuiltIn());
		$this->assertSame([], $module->getPolicies());
		$consent = new ProbeConsentModule();
		$consent->setStore(new TSessionConsentStore());
		$this->addModule('consent', $consent);
		$rights = (new TPrivacyModule())->setLogRequests(false);
		$this->addModule('rights', $rights);
		$this->assertSame([], $module->getPolicies(), 'no log, no database store, no request log');
		$consent->setLogEnabled(true)->setLog((new TDbConsentLog())->setDbConnection(TestDb::reset()));
		$consent->setStore((new TDbConsentStore())->setDbConnection(TestDb::connection()));
		$rights->setLogRequests(true)->setRequestLog((new TDbPrivacyRequestLog())->setDbConnection(TestDb::connection()));
		$policies = $module->setConsentLogPeriod('p5y')->setRequestLogPeriod('P2Y')->getPolicies();
		$this->assertSame(['consent-log', 'consent-state', 'privacy-requests'], \array_keys($policies));
		$this->assertSame('P5Y', $policies['consent-log']->getPeriod());
		$this->assertSame($consent->getLog(), $policies['consent-log']->getPurger());
		$this->assertSame('P0D', $policies['consent-state']->getPeriod());
		$this->assertSame('P2Y', $policies['privacy-requests']->getPeriod());
		$module->addPolicy(new TRetentionPolicy('consent-log', 'P1Y', new CountingPurger(0)));
		$this->assertSame('P1Y', $module->getPolicy('consent-log')->getPeriod(), 'a configured policy overrides a built-in one');
		$this->assertSame(['consent-log'], \array_keys($module->setIncludeBuiltIn('false')->getPolicies()));
		$this->assertSame('P5Y', $module->getConsentLogPeriod());
		$this->assertSame('P2Y', $module->getRequestLogPeriod());
		$this->expectException(TInvalidDataValueException::class);
		$module->setRequestLogPeriod('two years');
	}

	public function testPurgeRunsEveryPolicyAndReportsFailures()
	{
		$module = (new TRetentionModule())->setIncludeBuiltIn(false);
		$good = new CountingPurger(3);
		$module->addPolicy(new TRetentionPolicy('good', 'P1D', $good));
		$module->addPolicy(new TRetentionPolicy('no-purger', 'P1D'));
		$module->addPolicy(new TRetentionPolicy('after', 'PT1H', new CountingPurger(1)));
		$events = [];
		$module->attachEventHandler('onPurged', function ($sender, $param) use (&$events) {
			$events[] = $param->getParameter();
		});
		$this->assertSame(['good' => 3, 'no-purger' => -1, 'after' => 1], $module->purge());
		$this->assertSame([self::NOW - 86400], $good->calls);
		$this->assertSame([['good' => 3, 'no-purger' => -1, 'after' => 1]], $events);
	}

	public function testCronTaskInfo()
	{
		$module = new TRetentionModule();
		$module->setID('retention');
		$info = $module->fxGetCronTaskInfos(null, null);
		$this->assertInstanceOf(TCronTaskInfo::class, $info);
		$this->assertSame('privacyretention', $info->getName());
		$this->assertSame('retention->purge', $info->getTask());
	}

	public function testShellRegistration()
	{
		$shell = new TShellApplication(\dirname(__DIR__) . '/app', false);
		try {
			(new TRetentionModule())->init(null);
			$this->assertTrue($shell->hasShellActionClass(TPrivacyShellAction::class));
		} finally {
			Prado::setApplication($this->app);
		}
	}
}

/**
 * A purger that records its cutoffs and answers a count.
 */
class CountingPurger extends TComponent implements IRetentionPurger
{
	public array $calls = [];

	public function __construct(private int $count)
	{
		parent::__construct();
	}

	public function purge(int $before): int
	{
		$this->calls[] = $before;
		return $this->count;
	}
}
