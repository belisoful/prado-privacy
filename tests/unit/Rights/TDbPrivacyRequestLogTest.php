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
		$this->assertSame([$first, $second, $other], \array_column($log->findPending(), 'id'));
		$log->complete($first, 'completed', ['user' => ['items' => 2]], 120);
		$log->complete(9999, 'completed', [], 120);
		$entry = $log->find($first);
		$this->assertSame([
			'id' => $first, 'type' => 'export', 'subject_hash' => $hash, 'requested_by' => 'self',
			'requested_at' => 100, 'due_at' => 200, 'completed_at' => 120, 'status' => 'completed',
			'outcome' => ['user' => ['items' => 2]],
		], $entry);
		$pending = $log->find($second);
		$this->assertNull($pending['completed_at']);
		$this->assertNull($pending['outcome']);
		$this->assertNull($log->find(9999));
		$this->assertSame([$first, $second], \array_column($log->findBySubject($hash), 'id'));
		$this->assertSame([$second, $other], \array_column($log->findPending(), 'id'));
		$this->assertSame(2, $log->purge(160));
		$this->assertSame([$other], \array_column($log->findPending(), 'id'));
	}
}
