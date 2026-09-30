<?php

namespace belisoful\Privacy\Test\Unit\Consent;

use belisoful\Privacy\Consent\TConsentChangedEventParameter;
use belisoful\Privacy\Consent\TConsentRecord;
use PHPUnit\Framework\TestCase;

class TConsentChangedEventParameterTest extends TestCase
{
	public function testCarriesTheChange()
	{
		$record = new TConsentRecord(\str_repeat('a', 32), [], 'v', 1, 2);
		$param = new TConsentChangedEventParameter(
			['necessary' => 'granted', 'analytics' => 'undecided', 'marketing' => 'denied'],
			['necessary' => 'granted', 'analytics' => 'granted', 'marketing' => 'denied'],
			$record,
			'banner'
		);
		$this->assertSame('undecided', $param->getPrevious()['analytics']);
		$this->assertSame('granted', $param->getCurrent()['analytics']);
		$this->assertSame(['analytics' => 'granted'], $param->getChanged());
		$this->assertSame($record, $param->getRecord());
		$this->assertSame('banner', $param->getSource());
		$this->assertSame($param->getCurrent(), $param->getParameter());
	}
}
