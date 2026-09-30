<?php

namespace belisoful\Privacy\Test\Unit\Consent;

use belisoful\Privacy\Consent\TConsentModule;
use PHPUnit\Framework\TestCase;
use Prado\Exceptions\TInvalidDataValueException;

class TConsentModuleTest extends TestCase
{
	public function testVersionDefaultsAndTrims()
	{
		$module = new TConsentModule();
		$this->assertSame(TConsentModule::DEFAULT_VERSION, $module->getVersion());
		$this->assertSame($module, $module->setVersion(' 2026-09 '));
		$this->assertSame('2026-09', $module->getVersion());
		$module->setVersion(\str_repeat('v', 64));
		$this->assertSame(\str_repeat('v', 64), $module->getVersion());
	}

	public function testVersionRefusesEmpty()
	{
		$this->expectException(TInvalidDataValueException::class);
		(new TConsentModule())->setVersion('  ');
	}

	public function testVersionRefusesOverlong()
	{
		$this->expectException(TInvalidDataValueException::class);
		(new TConsentModule())->setVersion(\str_repeat('v', 65));
	}
}
