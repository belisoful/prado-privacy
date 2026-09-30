<?php

namespace belisoful\Privacy\Test\Unit\Consent;

use belisoful\Privacy\Consent\TConsentRecord;
use PHPUnit\Framework\TestCase;

class TConsentRecordTest extends TestCase
{
	private const ID = '0123456789abcdef0123456789abcdef';

	public function testAccessors()
	{
		$record = new TConsentRecord(self::ID, ['analytics' => 'granted', 'marketing' => 'denied'], 'v2', 100, 200, 'preferences');
		$this->assertSame(self::ID, $record->getId());
		$this->assertSame(['analytics' => 'granted', 'marketing' => 'denied'], $record->getCategories());
		$this->assertSame('granted', $record->getState('analytics'));
		$this->assertNull($record->getState('video'));
		$this->assertSame('v2', $record->getVersion());
		$this->assertSame(100, $record->getTime());
		$this->assertSame(200, $record->getExpires());
		$this->assertSame('preferences', $record->getSource());
		$this->assertFalse($record->isExpired(199));
		$this->assertTrue($record->isExpired(200));
		$this->assertSame('api', (new TConsentRecord(self::ID, [], 'v', 1, 2))->getSource());
	}

	public function testRoundTrip()
	{
		$record = new TConsentRecord(self::ID, ['analytics' => 'granted', 'marketing' => 'denied'], 'v2', 100, 200, 'banner');
		$array = $record->toArray();
		$this->assertSame(['id' => self::ID, 'v' => 'v2', 't' => 100, 'e' => 200, 's' => 'banner', 'c' => ['analytics' => 1, 'marketing' => 0]], $array);
		$copy = TConsentRecord::fromArray($array);
		$this->assertInstanceOf(TConsentRecord::class, $copy);
		$this->assertSame($record->getCategories(), $copy->getCategories());
		$this->assertSame('banner', $copy->getSource());
	}

	public static function malformed(): array
	{
		$good = ['id' => self::ID, 'v' => 'v', 't' => 1, 'e' => 2, 's' => 'api', 'c' => ['analytics' => 1]];
		return [
			'not an array' => ['x'],
			'no id' => [['id' => null] + $good],
			'bad id' => [['id' => 'XYZ'] + $good],
			'no version' => [['v' => 1] + $good],
			'no time' => [['t' => '1'] + $good],
			'no expiry' => [['e' => null] + $good],
			'no source' => [['s' => 5] + $good],
			'no categories' => [['c' => 'x'] + $good],
			'numeric category' => [['c' => [0 => 1]] + $good],
			'bad category' => [['c' => ['Bad Name' => 1]] + $good],
		];
	}

	/**
	 * @dataProvider malformed
	 * @param mixed $data
	 */
	public function testFromArrayRefusesMalformedData(mixed $data)
	{
		$this->assertNull(TConsentRecord::fromArray($data));
	}
}
