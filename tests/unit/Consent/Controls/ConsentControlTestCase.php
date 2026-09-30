<?php

namespace belisoful\Privacy\Test\Unit\Consent\Controls;

use belisoful\Privacy\Consent\TSessionConsentStore;
use belisoful\Privacy\Test\Unit\LifecyclePage;
use belisoful\Privacy\Test\Unit\PrivacyTestCase;
use belisoful\Privacy\Test\Unit\ProbeConsentModule;
use Prado\IO\TTextWriter;
use Prado\Web\Services\TPageService;
use Prado\Web\TAssetManager;
use Prado\Web\UI\THtmlWriter;

/**
 * The base of the control tests: a TPageService, an asset manager publishing into the test
 * runtime, and a ProbeConsentModule over a fake session under the id "privacy".
 */
abstract class ConsentControlTestCase extends PrivacyTestCase
{
	protected ProbeConsentModule $module;

	private mixed $_service;

	private mixed $_assets;

	protected function setUp(): void
	{
		parent::setUp();
		$this->_service = $this->app->getService();
		$this->app->setService(new TPageService());
		$property = new \ReflectionProperty(\Prado\TApplication::class, '_assetManager');
		$property->setAccessible(true);
		$this->_assets = $property->getValue($this->app);
		$assets = new TAssetManager();
		// TAssetManager takes BasePath in namespace form: Application.runtime.assets is tests/unit/app/runtime/assets.
		$path = \dirname(__DIR__, 2) . '/app/runtime/assets';
		if (!\is_dir($path)) {
			\mkdir($path, 0o777, true);
		}
		$assets->setBasePath('Application.runtime.assets');
		$assets->setBaseUrl('/assets');
		$this->app->setAssetManager($assets);
		$this->module = new ProbeConsentModule();
		$this->module->setStore(new TSessionConsentStore());
		$this->addModule('privacy', $this->module);
	}

	protected function tearDown(): void
	{
		$this->app->setService($this->_service);
		$property = new \ReflectionProperty(\Prado\TApplication::class, '_assetManager');
		$property->setAccessible(true);
		$property->setValue($this->app, $this->_assets);
		parent::tearDown();
	}

	/**
	 * A page holding the controls, initialized.
	 * @param array $controls
	 */
	protected function page(...$controls): LifecyclePage
	{
		return (new LifecyclePage($controls))->runInit();
	}

	/**
	 * A callback parameter whose getNewWriter() hands out recorded writers.
	 */
	protected function callbackParam(): object
	{
		return new class () {
			public array $writers = [];

			public function getNewWriter(): THtmlWriter
			{
				return $this->writers[] = new THtmlWriter(new TTextWriter());
			}
		};
	}
}
