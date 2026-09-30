<?php

namespace belisoful\Privacy\Test\Unit\Consent;

use belisoful\Privacy\Consent\TConsentLogContext;
use belisoful\Privacy\Consent\TConsentRecord;
use belisoful\Privacy\Consent\TDbConsentLog;
use belisoful\Privacy\Test\Unit\PrivacyTestCase;
use belisoful\Privacy\Test\Unit\TestDb;

class TDbConsentLogTest extends PrivacyTestCase
{
	private TDbConsentLog $log;

	protected function setUp(): void
	{
		parent::setUp();
		$this->log = (new TDbConsentLog())->setDbConnection(TestDb::reset());
	}

	public function testRecordAppendsAndFindsByIdAndSubject()
	{
		$id = \str_repeat('e', 32);
		$this->log->record(
			new TConsentRecord($id, ['necessary' => 'granted', 'analytics' => 'granted'], 'v1', 100, 200, 'banner'),
			new TConsentLogContext(\str_repeat('1', 64), 'DE', true, \str_repeat('2', 64), \str_repeat('3', 64))
		);
		$this->log->record(
			new TConsentRecord($id, ['necessary' => 'granted', 'analytics' => 'denied'], 'v1', 150, 250, 'preferences'),
			new TConsentLogContext()
		);
		$entries = $this->log->findByConsentId($id);
		$this->assertCount(2, $entries);
		$this->assertSame([
			'consent_id' => $id,
			'created_at' => 100,
			'version' => 'v1',
			'categories' => ['necessary' => 'granted', 'analytics' => 'granted'],
			'source' => 'banner',
			'region' => 'DE',
			'gpc' => true,
			'ip_hash' => \str_repeat('1', 64),
			'subject_hash' => \str_repeat('2', 64),
			'user_agent_hash' => \str_repeat('3', 64),
		], $entries[0]);
		$this->assertSame('preferences', $entries[1]['source']);
		$this->assertNull($entries[1]['region']);
		$this->assertFalse($entries[1]['gpc']);
		$this->assertCount(1, $this->log->findBySubject(\str_repeat('2', 64)));
		$this->assertSame([], $this->log->findByConsentId(\str_repeat('f', 32)));
	}

	public function testPurgeRemovesOlderEntries()
	{
		foreach ([100, 200, 300] as $time) {
			$this->log->record(new TConsentRecord(\str_repeat('a', 32), [], 'v', $time, $time), new TConsentLogContext());
		}
		$this->assertSame(2, $this->log->purge(300));
		$this->assertSame(1, TestDb::count('privacy_consent_log'));
	}
}
