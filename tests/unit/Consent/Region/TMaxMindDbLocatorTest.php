<?php

namespace belisoful\Privacy\Test\Unit\Consent\Region;

use belisoful\Privacy\Consent\Region\TMaxMindDbLocator;
use belisoful\Privacy\Test\Unit\MmdbWriter;
use belisoful\Privacy\Test\Unit\PrivacyTestCase;
use Prado\Exceptions\TConfigurationException;

class TMaxMindDbLocatorTest extends PrivacyTestCase
{
	private string $file;

	protected function setUp(): void
	{
		parent::setUp();
		$this->file = $this->app->getRuntimePath() . DIRECTORY_SEPARATOR . 'test-city.mmdb';
		\file_put_contents($this->file, MmdbWriter::build([
			'81.2.69.0/24' => ['country' => ['iso_code' => 'DE']],
			'175.16.199.0/24' => ['country' => ['iso_code' => 'US'], 'subdivisions' => [[['iso_code' => 'CA']]]],
			'89.160.20.0/24' => ['country_code' => 'SE'],
		]));
	}

	protected function tearDown(): void
	{
		@\unlink($this->file);
		parent::tearDown();
	}

	public function testLocatesFromTheDatabase()
	{
		$locator = (new TMaxMindDbLocator())->setFile($this->file);
		$this->assertSame($this->file, $locator->getFile());
		$this->assertSame('DE', $locator->locate('81.2.69.160')->getRegion());
		$location = $locator->locate('175.16.199.1');
		$this->assertSame('US-CA', $location->getRegion());
		$this->assertSame('mmdb', $location->getSource());
		$this->assertNull($locator->locate('8.8.8.8'), 'not in the database');
		$this->assertNull($locator->locate('2001:db8::1'), 'IPv6 in an IPv4 database is refused and logged');
		$this->assertSame($locator->getReader(), $locator->getReader());
	}

	public function testPathsForOtherFormats()
	{
		$locator = (new TMaxMindDbLocator())->setFile($this->file)->setCountryPath('country_code')->setSubdivisionPath('');
		$this->assertSame('country_code', $locator->getCountryPath());
		$this->assertSame('', $locator->getSubdivisionPath());
		$this->assertSame('SE', $locator->locate('89.160.20.1')->getRegion());
	}

	public function testAMissingFile()
	{
		$locator = (new TMaxMindDbLocator())->setFile('/no/such/file.mmdb');
		$this->assertSame('/no/such/file.mmdb', $locator->getFile());
		$this->expectException(TConfigurationException::class);
		$locator->locate('81.2.69.160');
	}

	public function testANamespacePath()
	{
		$locator = (new TMaxMindDbLocator())->setFile('Application.runtime.test-city');
		$this->assertStringEndsWith('test-city', $locator->getFile());
	}

	public function testAMissingReaderPackage()
	{
		$locator = new class () extends TMaxMindDbLocator {
			protected function getReaderClass(): string
			{
				return 'No\Such\Reader';
			}
		};
		$locator->setFile($this->file);
		$this->expectException(TConfigurationException::class);
		$locator->getReader();
	}
}
