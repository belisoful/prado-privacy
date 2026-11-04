<?php

namespace belisoful\Privacy\Test\Unit\Retention;

use belisoful\Privacy\Consent\TDbConsentLog;
use belisoful\Privacy\Consent\TDbConsentStore;
use belisoful\Privacy\Consent\TSessionConsentStore;
use belisoful\Privacy\Retention\IRetentionPurger;
use belisoful\Privacy\Retention\TMethodRetentionPurger;
use belisoful\Privacy\Retention\TRetentionCronTask;
use belisoful\Privacy\Retention\TRetentionModule;
use belisoful\Privacy\Retention\TRetentionPolicy;
use belisoful\Privacy\Rights\TDbPrivacyRequestLog;
use belisoful\Privacy\Rights\TPrivacyModule;
use belisoful\Privacy\Rights\TPrivacyShellAction;
use belisoful\Privacy\Test\Unit\PrivacyTestCase;
use belisoful\Privacy\Test\Unit\ProbeConsentModule;
use belisoful\Privacy\Test\Unit\TestDb;
use Prado\Exceptions\TCancelledException;
use Prado\Exceptions\TConfigurationException;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\Exceptions\TInvalidOperationException;
use Prado\IO\Async\TCancellationToken;
use Prado\Prado;
use Prado\Shell\TShellApplication;
use Prado\TComponent;
use Prado\Util\Cron\TCronModule;
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
		$this->assertSame(['good' => 3, 'no-purger' => -1, 'after' => 1], $module->purgePolicies());
		$this->assertSame([self::NOW - 86400], $good->calls);
		$this->assertSame([['good' => 3, 'no-purger' => -1, 'after' => 1]], $events);
		try {
			$module->purge();
			$this->fail('a failed policy fails the purge');
		} catch (TInvalidOperationException $e) {
			$this->assertSame('privacy_retention_purge_failed', $e->getErrorCode());
			$this->assertStringContainsString('no-purger', $e->getMessage());
		}
		$this->assertCount(2, $events, 'onPurged is raised before the purge throws');
		$this->assertCount(2, $good->calls, 'every policy ran');
		$module->addPolicy(new TRetentionPolicy('no-purger', 'P1D', new CountingPurger(0)));
		$this->assertSame(['good' => 3, 'no-purger' => 0, 'after' => 1], $module->purge());
	}

	public function testPurgeStopsWhenTheTokenIsCancelled()
	{
		$module = (new TRetentionModule())->setIncludeBuiltIn(false);
		$token = new TCancellationToken();
		$first = new CountingPurger(2);
		$module->addPolicy(new TRetentionPolicy('first', 'P1D', $first));
		$module->addPolicy(new TRetentionPolicy('cancels', 'P1D', new CancellingPurger($token)));
		$last = new CountingPurger(1);
		$module->addPolicy(new TRetentionPolicy('last', 'P1D', $last));
		$events = 0;
		$module->attachEventHandler('onPurged', function () use (&$events) {
			$events++;
		});
		try {
			$module->purge($token);
			$this->fail('cancelled');
		} catch (TCancelledException $e) {
			$this->assertSame($token->getReason(), $e);
		}
		$this->assertCount(1, $first->calls);
		$this->assertSame([], $last->calls, 'the policies after the deadline do not run');
		$this->assertSame(0, $events);

		$module->addPolicy(new TRetentionPolicy('cancels', 'P1D', new CancellingPurger(null)));
		try {
			$module->purgePolicies(new TCancellationToken());
			$this->fail('a purger\'s cancellation is rethrown');
		} catch (TCancelledException $e) {
			$this->assertSame('a wait was cancelled', $e->getMessage());
		}
		$this->assertCount(2, $first->calls);
		$this->assertSame([], $last->calls);
		$this->assertSame(['first' => 2, 'last' => 1], (new TRetentionModule())->setIncludeBuiltIn(false)
			->addPolicy(new TRetentionPolicy('first', 'P1D', $first))
			->addPolicy(new TRetentionPolicy('last', 'P1D', $last))->purge(new TCancellationToken()), 'a live token');
	}

	public function testADeadlineElapsesBetweenPolicies()
	{
		$slow = new class () extends CountingPurger {
			public function __construct()
			{
				parent::__construct(1);
			}

			public function purge(int $before): int
			{
				\usleep(60000);
				return parent::purge($before);
			}
		};
		$last = new CountingPurger(1);
		$module = (new TRetentionModule())->setIncludeBuiltIn(false)
			->addPolicy(new TRetentionPolicy('slow', 'P1D', $slow))
			->addPolicy(new TRetentionPolicy('last', 'P1D', $last));
		$this->expectException(TCancelledException::class);
		try {
			$module->purge(new TCancellationToken(0.02));
		} finally {
			$this->assertCount(1, $slow->calls);
			$this->assertSame([], $last->calls, 'the loop ran the deadline before the next policy');
		}
	}

	public function testCronTaskInfo()
	{
		$module = new TRetentionModule();
		$module->setID('retention');
		$info = $module->fxGetCronTaskInfos(null, null);
		$this->assertInstanceOf(TCronTaskInfo::class, $info);
		$this->assertSame('privacyretention', $info->getName());
		$this->assertSame(TRetentionCronTask::class, $info->getTask());
		$this->assertSame('retention', $info->getModuleId());
	}

	public function testCronTask()
	{
		$task = new TRetentionCronTask();
		try {
			$task->getRetentionModule();
			$this->fail('no module');
		} catch (TConfigurationException $e) {
			$this->assertSame('privacy_retention_module_missing', $e->getErrorCode());
		}
		$module = (new TRetentionModule())->setIncludeBuiltIn(false);
		$purger = new CountingPurger(5);
		$module->addPolicy(new TRetentionPolicy('uploads', 'P1D', $purger));
		$this->addModule('retention', $module);
		$this->assertSame($module, $task->getRetentionModule(), 'the first TRetentionModule');
		$task->setModuleId('retention');
		$this->assertSame($module, $task->getRetentionModule());
		$cron = new TCronModule();
		$task->execute($cron);
		$this->assertCount(1, $purger->calls);
		$this->addModule('other', new TCronModule());
		$task->setModuleId('other');
		try {
			$task->getRetentionModule();
			$this->fail('not a retention module');
		} catch (TConfigurationException $e) {
			$this->assertSame('privacy_retention_module_missing', $e->getErrorCode());
		}
		$task->setModuleId('retention');
		$token = new TCancellationToken();
		$token->cancel();
		$task->setCancellationToken($token);
		try {
			$task->execute($cron);
			$this->fail('the run\'s token reaches the purge');
		} catch (TCancelledException $e) {
			$this->assertCount(1, $purger->calls);
		}
		$task->setCancellationToken(null);
		$module->addPolicy(new TRetentionPolicy('broken', 'P1D'));
		$this->assertFalse($cron->runTask($task), 'TCronModule records a failed purge as a failed task');
		$this->assertCount(2, $purger->calls);
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

/**
 * A purger that cancels a token, or throws a cancellation of its own.
 */
class CancellingPurger extends TComponent implements IRetentionPurger
{
	public function __construct(private ?TCancellationToken $token)
	{
		parent::__construct();
	}

	public function purge(int $before): int
	{
		if ($this->token === null) {
			throw new TCancelledException('a wait was cancelled');
		}
		$this->token->cancel();
		return 0;
	}
}
