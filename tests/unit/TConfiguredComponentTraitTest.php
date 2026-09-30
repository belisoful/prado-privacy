<?php

namespace belisoful\Privacy\Test\Unit;

use belisoful\Privacy\Consent\IConsentStore;
use belisoful\Privacy\Consent\TCookieConsentStore;
use belisoful\Privacy\Consent\TSessionConsentStore;
use belisoful\Privacy\TConfiguredComponentTrait;
use Prado\Exceptions\TConfigurationException;
use Prado\Security\TAuthManager;
use Prado\TComponent;

class TConfiguredComponentTraitTest extends PrivacyTestCase
{
	private function resolver(): object
	{
		return new class () {
			use TConfiguredComponentTrait {
				resolveConfiguredComponent as public;
			}
		};
	}

	public function testAnInstancePassesThrough()
	{
		$store = new TSessionConsentStore();
		$this->assertSame($store, $this->resolver()->resolveConfiguredComponent($store, IConsentStore::class));
	}

	public function testAnInstanceOfTheWrongTypeIsRefused()
	{
		$this->expectException(TConfigurationException::class);
		$this->resolver()->resolveConfiguredComponent(new TComponent(), IConsentStore::class);
	}

	public function testAConfigurationArrayIsBuiltWithItsProperties()
	{
		$store = $this->resolver()->resolveConfiguredComponent(['class' => TCookieConsentStore::class, 'id' => 'ignored', 'CookieName' => 'c1'], IConsentStore::class);
		$this->assertInstanceOf(TCookieConsentStore::class, $store);
		$this->assertSame('c1', $store->getCookieName());
		$short = $this->resolver()->resolveConfiguredComponent(['class' => 'TSessionConsentStore'], IConsentStore::class);
		$this->assertInstanceOf(TSessionConsentStore::class, $short, 'a Prado3 short name resolves through the class map');
	}

	public static function badConfigurations(): array
	{
		return [
			'no class' => [['CookieName' => 'x']],
			'unknown class' => [['class' => 'NoSuchClassAnywhere']],
			'wrong interface' => [['class' => TComponent::class]],
			'not a component' => [['class' => \ArrayObject::class]],
		];
	}

	/**
	 * @dataProvider badConfigurations
	 * @param array $config
	 */
	public function testABadConfigurationIsRefused(array $config)
	{
		$this->expectException(TConfigurationException::class);
		$this->resolver()->resolveConfiguredComponent($config, IConsentStore::class);
	}

	public function testAModuleIdResolvesTheModule()
	{
		$this->addModule('auth-x', new TAuthManager());
		$this->expectException(TConfigurationException::class);
		$this->resolver()->resolveConfiguredComponent(' auth-x ', IConsentStore::class);
	}
}
