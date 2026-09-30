<?php

namespace belisoful\Privacy\Test\Unit;

use PHPUnit\Framework\TestCase;
use Prado\Prado;
use Prado\Security\TSecurityManager;
use Prado\TApplication;
use Prado\Util\Clock\TMockClock;

/**
 * The base of tests that need a request: installs a FakeRequest, a RecordingResponse, a FakeSession,
 * a guest FakeUser, a TSecurityManager with a fixed key and a TMockClock at 2026-09-29 12:00:00 UTC,
 * and restores the application's own in tearDown().
 */
abstract class PrivacyTestCase extends TestCase
{
	/** 2026-09-29 12:00:00 UTC */
	public const NOW = 1790683200;

	protected TApplication $app;

	protected FakeRequest $request;

	protected RecordingResponse $response;

	protected FakeSession $session;

	protected TMockClock $clock;

	private array $_saved = [];

	/** @var string[] module ids a test registered */
	private array $_modules = [];

	protected function setUp(): void
	{
		$this->app = Prado::getApplication();
		foreach (['_request', '_response', '_session', '_user', '_security'] as $property) {
			$this->_saved[$property] = $this->readAppProperty($property);
		}
		$this->_saved['clock'] = $this->readAppProperty('_clock');
		$this->request = new FakeRequest();
		$this->response = new RecordingResponse();
		$this->session = new FakeSession();
		$this->clock = new TMockClock();
		$this->clock->setTime(self::NOW);
		$this->app->setRequest($this->request);
		$this->app->setResponse($this->response);
		$this->app->setSession($this->session);
		$this->app->setUser(new FakeUser());
		$security = new TSecurityManager();
		$security->setValidationKey('prado-privacy-test-key');
		$this->app->setSecurityManager($security);
		$this->app->setClock($this->clock);
	}

	protected function tearDown(): void
	{
		// setModule() refuses a registered id, so the test's modules are removed from the registry directly.
		$modules = $this->findProperty('_modules');
		$registry = $modules->getValue($this->app);
		foreach ($this->_modules as $id) {
			unset($registry[$id]);
		}
		$modules->setValue($this->app, $registry);
		$this->_modules = [];
		foreach (['_request', '_response', '_session', '_user', '_security'] as $property) {
			$this->writeAppProperty($property, $this->_saved[$property]);
		}
		$this->writeAppProperty('_clock', $this->_saved['clock']);
	}

	protected function login(string $name): FakeUser
	{
		$user = new FakeUser($name, false);
		$this->app->setUser($user);
		return $user;
	}

	protected function addModule(string $id, \Prado\IModule $module): void
	{
		$this->app->setModule($id, $module);
		$this->_modules[] = $id;
	}

	private function readAppProperty(string $name): mixed
	{
		$property = $this->findProperty($name);
		return $property?->getValue($this->app);
	}

	private function writeAppProperty(string $name, mixed $value): void
	{
		$this->findProperty($name)?->setValue($this->app, $value);
	}

	private function findProperty(string $name): ?\ReflectionProperty
	{
		for ($class = new \ReflectionClass($this->app); $class !== false; $class = $class->getParentClass()) {
			if ($class->hasProperty($name)) {
				$property = $class->getProperty($name);
				$property->setAccessible(true);
				return $property;
			}
		}
		return null;
	}
}
