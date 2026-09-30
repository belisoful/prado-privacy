<?php

namespace belisoful\Privacy\Test\Unit\Rights;

use belisoful\Privacy\Rights\IPersonalDataProvider;
use belisoful\Privacy\Rights\IPrivacyRequestLog;
use belisoful\Privacy\Rights\TDataSubject;
use belisoful\Privacy\Rights\TDbPrivacyRequestLog;
use belisoful\Privacy\Rights\TErasureResult;
use belisoful\Privacy\Rights\TPrivacyModule;
use belisoful\Privacy\Rights\TPrivacyRequestEventParameter;
use belisoful\Privacy\Rights\TPrivacyShellAction;
use belisoful\Privacy\Rights\TSessionPersonalDataProvider;
use belisoful\Privacy\Test\Unit\PrivacyTestCase;
use belisoful\Privacy\Test\Unit\TestDb;
use Prado\Exceptions\TConfigurationException;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\Prado;
use Prado\Shell\TShellApplication;
use Prado\TModule;
use Prado\Xml\TXmlDocument;

class TPrivacyModuleTest extends PrivacyTestCase
{
	/** @var TPrivacyRequestEventParameter[] */
	private array $events = [];

	private function module(IPersonalDataProvider ...$providers): TPrivacyModule
	{
		$module = new TPrivacyModule();
		$module->setAutoDiscover(false);
		$module->setRequestLog((new TDbPrivacyRequestLog())->setDbConnection(TestDb::reset()));
		foreach ($providers as $provider) {
			$module->addProvider($provider);
		}
		foreach (['onPrivacyRequest', 'onPrivacyRequestComplete'] as $event) {
			$module->attachEventHandler($event, function ($sender, $param) {
				$this->events[] = $param;
			});
		}
		return $module;
	}

	public function testDefaults()
	{
		$module = new TPrivacyModule();
		$this->assertTrue($module->getAutoDiscover());
		$this->assertTrue($module->getLogRequests());
		$this->assertSame(30, $module->getResponseDays());
		$this->assertInstanceOf(TDbPrivacyRequestLog::class, $module->getRequestLog());
		$this->assertSame([], $module->getProviders());
	}

	public function testConfiguration()
	{
		$doc = new TXmlDocument();
		$doc->loadFromString('<module>
			<provider class="belisoful\Privacy\Rights\TSessionPersonalDataProvider" />
			<requestlog class="belisoful\Privacy\Rights\TDbPrivacyRequestLog" ConnectionID="db" />
		</module>');
		$module = new TPrivacyModule();
		$module->init($doc);
		$this->assertInstanceOf(TSessionPersonalDataProvider::class, $module->getProviders()['session']);
		$this->assertSame('db', $module->getRequestLog()->getConnectionID());
		$php = new TPrivacyModule();
		$php->init(['providers' => [['class' => TSessionPersonalDataProvider::class]], 'requestlog' => new RecordingRequestLog()]);
		$this->assertArrayHasKey('session', $php->getProviders());
		$this->assertInstanceOf(RecordingRequestLog::class, $php->getRequestLog());
		$empty = new TPrivacyModule();
		$empty->init(null);
		$empty->init(new TXmlDocument());
		$empty->setRequestLog('');
		$this->assertInstanceOf(TDbPrivacyRequestLog::class, $empty->getRequestLog());
	}

	public function testPropertiesAreValidated()
	{
		$module = new TPrivacyModule();
		$this->assertSame($module, $module->setResponseDays('45')->setLogRequests('false'));
		$this->assertSame(45, $module->getResponseDays());
		$this->assertFalse($module->getLogRequests());
		$this->expectException(TInvalidDataValueException::class);
		$module->setResponseDays(0);
	}

	public function testDiscoveryAndDuplicates()
	{
		$provider = new ArrayProvider('orders', ['o1', 'o2']);
		$this->addModule('orders', $provider);
		$this->addModule('plain', new TSessionPersonalDataProvider());
		$module = new TPrivacyModule();
		$module->addProvider($provider);
		$this->assertSame(['orders', 'session'], \array_keys($module->getProviders()), 'a configured module is listed once');
		$module->setAutoDiscover(false);
		$this->assertSame(['orders'], \array_keys($module->getProviders()));
		$module->addProvider(new ArrayProvider('orders', []));
		$this->expectException(TConfigurationException::class);
		$module->getProviders();
	}

	public function testExportRecordsTheRequest()
	{
		$module = $this->module(new ArrayProvider('orders', ['o1', 'o2']), new ArrayProvider('tickets', []));
		$export = $module->export(new TDataSubject('alice', 'a@example.test'), 'self');
		$this->assertSame(['name' => 'alice', 'email' => 'a@example.test', 'identifiers' => []], $export['subject']);
		$this->assertSame('2026-09-29T12:00:00Z', $export['generated']);
		$this->assertSame(['orders' => ['o1', 'o2'], 'tickets' => []], $export['data']);
		$entry = $module->getRequestLog()->find($export['request']);
		$this->assertSame('export', $entry['type']);
		$this->assertSame('completed', $entry['status']);
		$this->assertSame(['orders' => ['items' => 2], 'tickets' => ['items' => 0]], $entry['outcome']);
		$this->assertSame(self::NOW + 30 * 86400, $entry['due_at']);
		$this->assertSame('self', $entry['requested_by']);
		$this->assertCount(2, $this->events);
		$this->assertNull($this->events[0]->getStatus());
		$this->assertSame('completed', $this->events[1]->getStatus());
		$json = \json_decode($module->exportJson(new TDataSubject('alice')), true);
		$this->assertSame(['o1', 'o2'], $json['data']['orders']);
	}

	public function testEraseCollectsResultsAndStatuses()
	{
		$module = $this->module(new ArrayProvider('orders', ['o1']), new ArrayProvider('broken', [], true));
		$results = $module->erase(new TDataSubject('alice'));
		$this->assertSame(1, $results['orders']->getErased());
		$this->assertSame(['RuntimeException'], $results['broken']->getErrors());
		$entry = $module->getRequests(new TDataSubject('alice'))[0];
		$this->assertSame('partial', $entry['status']);
		$this->assertSame(['error' => 'RuntimeException'], $entry['outcome']['broken']);
		$this->assertStringNotContainsString('alice', \json_encode($entry), 'no personal data in the log');
		$failed = $this->module(new ArrayProvider('broken', [], true));
		$failed->erase(new TDataSubject('bob'));
		$this->assertSame('failed', $failed->getRequests(new TDataSubject('bob'))[0]['status']);
	}

	public function testRectifyRunsTheNamedProviders()
	{
		$orders = new ArrayProvider('orders', []);
		$module = $this->module($orders, new ArrayProvider('tickets', []));
		$this->assertSame(['orders' => true], $module->rectify(new TDataSubject('alice'), ['orders' => ['email' => 'new@example.test']]));
		$this->assertSame(['email' => 'new@example.test'], $orders->rectified);
		$entry = $module->getRequests(new TDataSubject('alice'))[0];
		$this->assertSame(['orders' => ['fields' => ['email'], 'applied' => true]], $entry['outcome']);
		$this->expectException(TInvalidDataValueException::class);
		$module->rectify(new TDataSubject('alice'), ['nope' => []]);
	}

	public function testWithoutTheRequestLog()
	{
		$module = $this->module(new ArrayProvider('orders', ['o1']))->setLogRequests(false);
		$log = new RecordingRequestLog();
		$module->setRequestLog($log);
		$this->assertSame(0, $module->export(new TDataSubject('alice'))['request']);
		$this->assertSame(0, $module->openRequest('erase', new TDataSubject('alice')));
		$this->assertSame([], $log->calls);
	}

	public function testOpenRequestAndOverdue()
	{
		$module = $this->module();
		$id = $module->openRequest('erase', new TDataSubject('alice'), 'mail');
		$this->assertSame([], $module->getOverdueRequests());
		$this->clock->setTime(self::NOW + 31 * 86400);
		$this->assertSame([$id], \array_column($module->getOverdueRequests(), 'id'));
		$this->expectException(TInvalidDataValueException::class);
		$module->openRequest('delete', new TDataSubject('alice'));
	}

	public function testShellActionRegistration()
	{
		$module = new TPrivacyModule();
		$this->assertFalse($module->registerShellAction(), 'a web application gets no shell action');
		$shell = new TShellApplication(\dirname(__DIR__) . '/app', false);
		try {
			$module->init(null);
			$this->assertTrue($shell->hasShellActionClass(TPrivacyShellAction::class));
			$this->assertFalse($module->registerShellAction(), 'registered once');
			$this->assertSame($module, $shell->getShellActions()[TPrivacyShellAction::class]->getModule());
		} finally {
			Prado::setApplication($this->app);
		}
	}
}

/**
 * A provider over an array; optionally throws.
 */
class ArrayProvider extends TModule implements IPersonalDataProvider
{
	public ?array $rectified = null;

	public function __construct(private string $name, private array $data, private bool $throws = false)
	{
		parent::__construct();
	}

	public function getPersonalDataName(): string
	{
		return $this->name;
	}

	public function exportPersonalData(TDataSubject $subject): array
	{
		$this->fail();
		return $this->data;
	}

	public function erasePersonalData(TDataSubject $subject): TErasureResult
	{
		$this->fail();
		return new TErasureResult(\count($this->data));
	}

	public function rectifyPersonalData(TDataSubject $subject, array $changes): bool
	{
		$this->rectified = $changes;
		return true;
	}

	private function fail(): void
	{
		if ($this->throws) {
			throw new \RuntimeException('provider failed for ' . 'alice');
		}
	}
}

/**
 * An IPrivacyRequestLog that records its calls.
 */
class RecordingRequestLog extends \Prado\TComponent implements IPrivacyRequestLog
{
	public array $calls = [];

	public function open(string $type, string $subjectHash, ?string $requestedBy, int $requestedAt, int $dueAt): int
	{
		$this->calls[] = 'open';
		return 1;
	}

	public function complete(int $id, string $status, array $outcome, int $completedAt): void
	{
		$this->calls[] = 'complete';
	}

	public function find(int $id): ?array
	{
		return null;
	}

	public function findBySubject(string $subjectHash): array
	{
		return [];
	}

	public function findPending(): array
	{
		return [];
	}

	public function purge(int $before): int
	{
		return 0;
	}
}
