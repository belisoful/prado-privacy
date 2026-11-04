<?php

namespace belisoful\Privacy\Test\Unit\Rights;

use belisoful\Privacy\Rights\TDbPrivacyRequestLog;
use belisoful\Privacy\Test\Unit\PrivacyTestCase;
use belisoful\Privacy\Test\Unit\TestDb;

class TDbPrivacyRequestLogTest extends PrivacyTestCase
{
	public function testOpenCompleteAndFind()
	{
		$log = (new TDbPrivacyRequestLog())->setDbConnection(TestDb::reset());
		$hash = \str_repeat('a', 64);
		$first = $log->open('export', $hash, 'self', 100, 200);
		$second = $log->open('erase', $hash, null, 150, 250);
		$other = $log->open('export', \str_repeat('b', 64), 'operator', 160, 260);
		$this->assertGreaterThan(0, $first);
		$this->assertSame([$first, $second, $other], \array_column($log->findOpen(), 'id'));
		$log->complete($first, 'completed', ['user' => ['items' => 2]], 120);
		$log->complete(9999, 'completed', [], 120);
		$entry = $log->find($first);
		$this->assertSame([
			'id' => $first, 'type' => 'export', 'subject_hash' => $hash, 'requested_by' => 'self',
			'requested_at' => 100, 'due_at' => 200, 'completed_at' => 120, 'status' => 'completed',
			'outcome' => ['user' => ['items' => 2]], 'extensions' => [],
		], $entry);
		$pending = $log->find($second);
		$this->assertNull($pending['completed_at']);
		$this->assertNull($pending['outcome']);
		$this->assertNull($log->find(9999));
		$this->assertSame([$first, $second], \array_column($log->findBySubject($hash), 'id'));
		$this->assertSame([$second, $other], \array_column($log->findOpen(), 'id'));
	}

	public function testOpenStatusesAndPurge()
	{
		$log = (new TDbPrivacyRequestLog())->setDbConnection(TestDb::reset());
		$hash = \str_repeat('a', 64);
		$done = $log->open('export', $hash, null, 100, 200);
		$partial = $log->open('erase', $hash, null, 100, 200);
		$failed = $log->open('erase', $hash, null, 100, 200);
		$pending = $log->open('erase', $hash, null, 100, 200);
		$recent = $log->open('export', $hash, null, 100, 200);
		$log->complete($done, 'completed', [], 110);
		$log->complete($partial, 'partial', [], 110);
		$log->complete($failed, 'failed', [], 110);
		$log->complete($recent, 'completed', [], 500);
		$this->assertSame([$partial, $failed, $pending], \array_column($log->findOpen(), 'id'));
		$this->assertSame(1, $log->purge(400), 'only completed requests answered before the cutoff');
		$this->assertSame([$partial, $failed, $pending, $recent], \array_column($log->findBySubject($hash), 'id'));
	}

	public function testExtendAndTruncation()
	{
		$log = (new TDbPrivacyRequestLog())->setDbConnection(TestDb::reset());
		$id = $log->open('erase', \str_repeat('a', 64), \str_repeat('é', 300), 100, 200);
		$this->assertSame(\str_repeat('é', 255), $log->find($id)['requested_by']);
		$log->extend($id, 300, 'complex', 150);
		$log->extend($id, 400, 'many requests', 160);
		$log->extend(9999, 400, 'ignored', 160);
		$entry = $log->find($id);
		$this->assertSame(400, $entry['due_at']);
		$this->assertNull($entry['outcome']);
		$extensions = [['reason' => 'complex', 'from' => 200, 'to' => 300, 'at' => 150], ['reason' => 'many requests', 'from' => 300, 'to' => 400, 'at' => 160]];
		$this->assertSame($extensions, $entry['extensions']);
		$log->complete($id, 'completed', ['user' => ['erased' => 1]], 170);
		$entry = $log->find($id);
		$this->assertSame(['user' => ['erased' => 1]], $entry['outcome']);
		$this->assertSame($extensions, $entry['extensions']);
	}
}
