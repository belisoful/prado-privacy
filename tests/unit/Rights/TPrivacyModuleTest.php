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
use Prado\TApplicationMode;
use Prado\TModule;
use Prado\Util\Log\TLogger;
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
		$this->assertSame('P1M', $module->getResponsePeriod());
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
		$this->assertSame($module, $module->setResponsePeriod(' p45d ')->setLogRequests('false'));
		$this->assertSame('P45D', $module->getResponsePeriod());
		$this->assertFalse($module->getLogRequests());
		foreach (['P0D', 'PT0S', 'forty days', ''] as $invalid) {
			try {
				$module->setResponsePeriod($invalid);
				$this->fail($invalid . ' is refused');
			} catch (TInvalidDataValueException $e) {
				$this->assertStringContainsString('ISO 8601 duration above zero', $e->getMessage());
			}
		}
		$this->assertSame('P45D', $module->getResponsePeriod());
	}

	public function testCalendarPeriods()
	{
		$at = fn (string $date) => (new \DateTimeImmutable($date, new \DateTimeZone('UTC')))->getTimestamp();
		$this->assertSame($at('2027-03-01 09:30'), TPrivacyModule::addPeriod($at('2027-02-01 09:30'), 'P1M'));
		$this->assertSame($at('2027-02-28 00:00'), TPrivacyModule::addPeriod($at('2027-01-31 00:00'), 'P1M'), 'the last day of a shorter month');
		$this->assertSame($at('2028-02-29 00:00'), TPrivacyModule::addPeriod($at('2028-01-31 00:00'), 'P1M'));
		$this->assertSame($at('2027-03-31 00:00'), TPrivacyModule::addPeriod($at('2027-01-31 00:00'), 'P2M'));
		$this->assertSame($at('2028-03-02 06:00'), TPrivacyModule::addPeriod($at('2027-01-31 00:00'), 'P1Y1M2DT6H'));
		$this->assertSame($at('2027-03-18 00:00'), TPrivacyModule::addPeriod($at('2027-02-01 00:00'), 'P45D'));
		$module = $this->module()->setResponsePeriod('P45D');
		$entry = $module->getRequestLog()->find($module->openRequest('export', new TDataSubject('alice')));
		$this->assertSame(self::NOW + 45 * 86400, $entry['due_at']);
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
		$this->assertSame('completed', $export['status']);
		$this->assertSame([], $export['errors']);
		$this->assertSame([$export['request']], \array_column($export['requests'], 'id'), 'the history includes this request');
		$this->assertSame('completed', $export['requests'][0]['status']);
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
		$this->assertCount(2, $json['requests']);
	}

	public function testExportReportsFailedProviders()
	{
		$module = $this->module(new ArrayProvider('orders', ['o1']), new ArrayProvider('broken', [], true));
		$export = $module->export(new TDataSubject('alice'));
		$this->assertSame('partial', $export['status']);
		$this->assertSame(['broken' => 'RuntimeException'], $export['errors']);
		$this->assertSame(['orders' => ['o1'], 'broken' => null], $export['data']);
		$this->assertSame('failed', $this->module(new ArrayProvider('broken', [], true))->export(new TDataSubject('alice'))['status']);
	}

	public function testExportJsonSubstitutesInvalidUtf8()
	{
		$module = $this->module(new ArrayProvider('orders', ["bad \xB1 byte"]));
		$json = \json_decode($module->exportJson(new TDataSubject('alice')), true);
		$this->assertSame("bad \u{FFFD} byte", $json['data']['orders'][0]);
	}

	public function testProviderMessagesAreLoggedInDebugOnly()
	{
		$logger = Prado::getLogger();
		$mode = $this->app->getMode();
		$logger->deleteLogs(null, TPrivacyModule::class);
		try {
			$this->app->setMode(TApplicationMode::Normal);
			$id = $this->module(new ArrayProvider('broken', [], true))->export(new TDataSubject('alice'))['request'];
			$normal = \array_column($logger->deleteLogs(TLogger::ERROR, TPrivacyModule::class), 0);
			$this->assertSame(['The export request ' . $id . ' failed in provider "broken": RuntimeException'], $normal);
			$this->app->setMode(TApplicationMode::Debug);
			$this->module(new ArrayProvider('broken', [], true))->export(new TDataSubject('alice'));
			$debug = \array_column($logger->deleteLogs(TLogger::ERROR, TPrivacyModule::class), 0);
			$this->assertStringEndsWith('RuntimeException: provider failed for alice', $debug[0]);
		} finally {
			$this->app->setMode($mode);
		}
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

	public function testErasureErrorsCountAsFailures()
	{
		$module = $this->module(new ArrayProvider('orders', ['o1']), new ErrorProvider());
		$results = $module->erase(new TDataSubject('alice'));
		$this->assertSame(['Locked'], $results['errors']->getErrors());
		$this->assertSame('partial', $module->getRequests(new TDataSubject('alice'))[0]['status']);
	}

	public function testRectifyRunsTheNamedProviders()
	{
		$orders = new ArrayProvider('orders', []);
		$module = $this->module($orders, new ArrayProvider('tickets', []));
		$this->assertSame(['orders' => true], $module->rectify(new TDataSubject('alice'), ['orders' => ['email' => 'new@example.test']]));
		$this->assertSame(['email' => 'new@example.test'], $orders->rectified);
		$entry = $module->getRequests(new TDataSubject('alice'))[0];
		$this->assertSame(['orders' => ['fields' => ['email'], 'applied' => true]], $entry['outcome']);
		$this->assertSame('completed', $entry['status']);
		$this->expectException(TInvalidDataValueException::class);
		$module->rectify(new TDataSubject('alice'), ['nope' => []]);
	}

	public function testRectifyFailures()
	{
		$refusing = new ArrayProvider('tickets', []);
		$refusing->applies = false;
		$module = $this->module(new ArrayProvider('orders', []), $refusing, new ArrayProvider('broken', [], true));
		$applied = $module->rectify(new TDataSubject('alice'), ['orders' => ['a' => 1], 'tickets' => ['a' => 1], 'broken' => ['a' => 1]]);
		$this->assertSame(['orders' => true, 'tickets' => false, 'broken' => false], $applied, 'a provider that threw is false');
		$this->assertSame('partial', $module->getRequests(new TDataSubject('alice'))[0]['status']);
		$module->rectify(new TDataSubject('bob'), ['tickets' => ['a' => 1]]);
		$this->assertSame('failed', $module->getRequests(new TDataSubject('bob'))[0]['status']);
	}

	public function testAnswersAnOpenRequest()
	{
		$module = $this->module(new ArrayProvider('orders', ['o1']));
		$alice = new TDataSubject('alice');
		$id = $module->openRequest('erase', $alice, 'letter');
		$this->assertSame(1, $module->erase($alice, 'ignored', $id)['orders']->getErased());
		$entries = $module->getRequests($alice);
		$this->assertCount(1, $entries, 'no second row');
		$this->assertSame('completed', $entries[0]['status']);
		$this->assertSame('letter', $entries[0]['requested_by']);
		$exportId = $module->openRequest('export', $alice);
		$this->assertSame($exportId, \json_decode($module->exportJson($alice, null, $exportId), true)['request']);
		$rectifyId = $module->openRequest('rectify', $alice);
		$module->rectify($alice, ['orders' => ['a' => 1]], null, $rectifyId);
		$this->assertSame('completed', $module->getRequestLog()->find($rectifyId)['status']);
		$open = $module->openRequest('export', $alice);
		foreach ([[$id, $alice], [9999, $alice], [$open, new TDataSubject('bob')]] as [$request, $subject]) {
			try {
				$module->export($subject, null, $request);
				$this->fail('request ' . $request . ' is refused');
			} catch (TInvalidDataValueException $e) {
				$this->assertStringContainsString('is unknown, closed, or of another type or subject', $e->getMessage());
			}
		}
		try {
			$module->erase($alice, null, $open);
			$this->fail('another type is refused');
		} catch (TInvalidDataValueException $e) {
			$this->assertStringContainsString('(erase)', $e->getMessage());
		}
		$this->assertSame(0, $module->setLogRequests(false)->export($alice, null, 9999)['request'], 'ignored without the log');
	}

	public function testRetriesAPartialRequest()
	{
		$broken = new ArrayProvider('orders', ['o1'], true);
		$module = $this->module($broken, new ArrayProvider('tickets', []));
		$alice = new TDataSubject('alice');
		$id = $module->export($alice)['request'];
		$this->assertSame('partial', $module->getRequestLog()->find($id)['status']);
		$this->assertSame([$id], \array_column($module->getRequestLog()->findOpen(), 'id'));
		$broken->throws = false;
		$this->assertSame('completed', $module->export($alice, null, $id)['status']);
		$this->assertSame([], $module->getRequestLog()->findOpen());
	}

	public function testExtendRequest()
	{
		$module = $this->module(new ArrayProvider('orders', []));
		$alice = new TDataSubject('alice');
		$id = $module->openRequest('erase', $alice);
		$this->clock->setTime(self::NOW + 86400);
		$due = $module->extendRequest($id, 'p2m', ' many requests ');
		$this->assertSame((new \DateTimeImmutable('2026-12-29 12:00', new \DateTimeZone('UTC')))->getTimestamp(), $due);
		$entry = $module->getRequestLog()->find($id);
		$this->assertSame($due, $entry['due_at']);
		$this->assertNull($entry['outcome']);
		$this->assertSame([['reason' => 'many requests', 'from' => self::NOW + 30 * 86400, 'to' => $due, 'at' => self::NOW + 86400]], $entry['extensions']);
		$module->erase($alice, null, $id);
		$entry = $module->getRequestLog()->find($id);
		$this->assertSame(['orders' => ['erased' => 0, 'retained' => [], 'errors' => []]], $entry['outcome']);
		$this->assertCount(1, $entry['extensions'], 'answering keeps the extensions');
		foreach ([[$id, 'P1M', 'late'], [9999, 'P1M', 'late'], [$id, 'P0D', 'late'], [$id, 'P1M', ' ']] as [$request, $period, $reason]) {
			try {
				$module->extendRequest($request, $period, $reason);
				$this->fail('the extension is refused');
			} catch (TInvalidDataValueException $e) {
				$this->assertNotSame('', $e->getMessage());
			}
		}
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
		$module->getRequestLog()->complete($id, 'failed', [], self::NOW);
		$this->assertSame([$id], \array_column($module->getOverdueRequests(), 'id'), 'a failed request is still open');
		$module->getRequestLog()->complete($id, 'partial', [], self::NOW);
		$this->assertSame([$id], \array_column($module->getOverdueRequests(), 'id'), 'a partial request is still open');
		$module->getRequestLog()->complete($id, 'completed', [], self::NOW);
		$this->assertSame([], $module->getOverdueRequests());
		$this->expectException(TInvalidDataValueException::class);
		$module->openRequest('delete', new TDataSubject('alice'));
	}

	public function testProcessingActivity()
	{
		$activity = (new TPrivacyModule())->getProcessingActivities()[0];
		$this->assertSame('privacy-requests', $activity->getName());
		$this->assertSame('privacy-requests', $activity->getRetentionPolicy());
		$this->assertSame('', $activity->getRetentionPeriod());
		$off = (new TPrivacyModule())->setLogRequests(false)->getProcessingActivities()[0];
		$this->assertSame('', $off->getRetentionPolicy());
		$this->assertSame('not recorded', $off->getRetentionPeriod());
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

	public bool $applies = true;

	public function __construct(private string $name, private array $data, public bool $throws = false)
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
		$this->fail();
		$this->rectified = $changes;
		return $this->applies;
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

	public function extend(int $id, int $dueAt, string $reason, int $extendedAt): void
	{
		$this->calls[] = 'extend';
	}

	public function findOpen(): array
	{
		return [];
	}

	public function purge(int $before): int
	{
		return 0;
	}
}

/**
 * A provider whose erasure reports an error without throwing.
 */
class ErrorProvider extends ArrayProvider
{
	public function __construct()
	{
		parent::__construct('errors', []);
	}

	public function erasePersonalData(TDataSubject $subject): TErasureResult
	{
		return (new TErasureResult())->addError('Locked');
	}
}
