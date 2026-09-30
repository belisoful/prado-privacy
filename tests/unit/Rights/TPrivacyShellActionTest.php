<?php

namespace belisoful\Privacy\Test\Unit\Rights;

use belisoful\Privacy\Rights\TDataSubject;
use belisoful\Privacy\Rights\TDbPrivacyRequestLog;
use belisoful\Privacy\Rights\TPrivacyModule;
use belisoful\Privacy\Rights\TPrivacyShellAction;
use belisoful\Privacy\Test\Unit\PrivacyTestCase;
use belisoful\Privacy\Test\Unit\TestDb;
use Prado\IO\TTextWriter;
use Prado\Shell\TShellAction;
use Prado\Shell\TShellWriter;

class TPrivacyShellActionTest extends PrivacyTestCase
{
	private TTextWriter $out;

	private function action(?TPrivacyModule $module): TPrivacyShellAction
	{
		$this->out = new TTextWriter();
		$writer = new TShellWriter($this->out);
		$writer->setColorSupported(false);
		$action = new TPrivacyShellAction();
		$action->setWriter($writer);
		$action->setModule($module);
		return $action;
	}

	private function module(): TPrivacyModule
	{
		$module = new TPrivacyModule();
		$module->setAutoDiscover(false);
		$module->setRequestLog((new TDbPrivacyRequestLog())->setDbConnection(TestDb::reset()));
		$module->addProvider(new ArrayProvider('orders', ['o1']));
		$module->addProvider(new RetainingProvider());
		return $module;
	}

	public function testContract()
	{
		$action = new TPrivacyShellAction();
		$this->assertInstanceOf(TShellAction::class, $action);
		$this->assertSame('privacy', $action->getAction());
		$this->assertSame(['by'], $action->options('export'));
		$this->assertSame([], $action->options('requests'));
		$this->assertSame('operator', $action->getBy());
		$action->setBy(' dpo ');
		$this->assertSame('dpo', $action->getBy());
		$action->setBy('');
		$this->assertSame('operator', $action->getBy());
	}

	public function testExport()
	{
		$module = $this->module();
		$action = $this->action($module);
		$action->setBy('dpo');
		$this->assertTrue($action->actionExport(['privacy/export', 'alice']));
		$json = \json_decode($this->out->flush(), true);
		$this->assertSame(['o1'], $json['data']['orders']);
		$this->assertSame('dpo', $module->getRequests(new TDataSubject('alice'))[0]['requested_by']);
	}

	public function testErase()
	{
		$action = $this->action($this->module());
		$this->assertTrue($action->actionErase(['privacy/erase', 'alice']));
		$printed = $this->out->flush();
		$this->assertStringContainsString('orders: erased 1', $printed);
		$this->assertStringContainsString('ledger: erased 0; retained 2 (tax law); errors Oops', $printed);
	}

	public function testRequests()
	{
		$module = $this->module();
		$action = $this->action($module);
		$action->actionRequests(['privacy/requests']);
		$this->assertStringContainsString('No pending requests.', $this->out->flush());
		$module->openRequest('erase', new TDataSubject('alice'), 'mail');
		$module->openRequest('export', new TDataSubject('bob'));
		$action->actionRequests(['privacy/requests']);
		$printed = $this->out->flush();
		$this->assertStringContainsString('erase requested 2026-09-29, due 2026-10-29, by mail', $printed);
		$this->assertStringContainsString('export requested 2026-09-29, due 2026-10-29, by -', $printed);
		$action->actionRequests(['privacy/requests', 'overdue']);
		$this->assertStringContainsString('No overdue requests.', $this->out->flush());
	}

	public function testWithoutAModule()
	{
		$action = $this->action(null);
		$this->assertNull($action->getModule());
		foreach (['actionExport', 'actionErase', 'actionRequests'] as $method) {
			$this->assertTrue($action->$method(['privacy', 'alice']));
			$this->assertStringContainsString('No TPrivacyModule is configured.', $this->out->flush());
		}
	}

	public function testPurge()
	{
		$action = $this->action(null);
		$action->actionPurge(['privacy/purge']);
		$this->assertStringContainsString('No TRetentionModule is configured.', $this->out->flush());
		$retention = (new \belisoful\Privacy\Retention\TRetentionModule())->setIncludeBuiltIn(false);
		$retention->addPolicy(new \belisoful\Privacy\Retention\TRetentionPolicy('uploads', 'P1D', (new \belisoful\Privacy\Retention\TDbRetentionPurger())->setDbConnection(TestDb::reset())->setTable('privacy_request')->setTimeColumn('requested_at')));
		$retention->addPolicy(new \belisoful\Privacy\Retention\TRetentionPolicy('broken', 'P1D'));
		$this->addModule('retention', $retention);
		$this->assertSame($retention, $action->getRetentionModule());
		$this->assertTrue($action->actionPurge(['privacy/purge']));
		$printed = $this->out->flush();
		$this->assertStringContainsString('uploads: 0 removed', $printed);
		$this->assertStringContainsString('broken: failed (see the application log)', $printed);
		$action->setRetentionModule(null);
	}

	public function testRopa()
	{
		$action = $this->action(null);
		$action->actionRopa(['privacy/ropa']);
		$this->assertStringContainsString('No TProcessingRegistry is configured.', $this->out->flush());
		$registry = (new \belisoful\Privacy\Retention\TProcessingRegistry())->setAutoDiscover(false)->setController('Example');
		$registry->addActivity(['Name' => 'accounts', 'LegalBasis' => 'contract']);
		$action->setRegistry($registry);
		$this->assertSame($registry, $action->getRegistry());
		$this->assertTrue($action->actionRopa(['privacy/ropa']));
		$this->assertStringContainsString('# Records of processing activities', $this->out->flush());
		$action->actionRopa(['privacy/ropa', 'json']);
		$this->assertSame('Example', \json_decode($this->out->flush(), true)['controller']);
		$this->addModule('ropa', $registry);
		$this->assertSame($registry, $this->action(null)->getRegistry(), 'found by type');
	}

	public function testFindsTheApplicationsModule()
	{
		$module = $this->module();
		$this->addModule('rights', $module);
		$this->assertSame($module, $this->action(null)->getModule());
	}
}

/**
 * A provider that retains and reports an error on erasure.
 */
class RetainingProvider extends ArrayProvider
{
	public function __construct()
	{
		parent::__construct('ledger', []);
	}

	public function erasePersonalData(TDataSubject $subject): \belisoful\Privacy\Rights\TErasureResult
	{
		return (new \belisoful\Privacy\Rights\TErasureResult())->addRetained(2, 'tax law')->addError('Oops');
	}
}
