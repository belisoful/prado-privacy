<?php

/**
 * TConfiguredComponentTrait class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy;

use Prado\Exceptions\TConfigurationException;
use Prado\Prado;
use Prado\TComponent;

/**
 * TConfiguredComponentTrait trait.
 *
 * TConfiguredComponentTrait resolves a pluggable part (a store, a log, a resolver, a locator)
 * from the three forms a configuration gives it:
 *
 * | Value | Result |
 * |---|---|
 * | an object implementing the interface | used as is |
 * | an array with `class` and properties | `Prado::createComponent()` of the class, each other key set as a (sub)property; `id` is ignored |
 * | a string | the id of an application module, loaded when first used |
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
trait TConfiguredComponentTrait
{
	/**
	 * Resolves a configured part to an instance of an interface.
	 * @param mixed $value an instance, a configuration array, or a module id
	 * @param string $interface the interface the part implements
	 * @throws TConfigurationException when the class or the module does not implement the interface
	 * @return object the part
	 */
	protected function resolveConfiguredComponent(mixed $value, string $interface): object
	{
		if (\is_object($value)) {
			if (!($value instanceof $interface)) {
				throw new TConfigurationException('privacy_component_invalid', $value::class, $interface);
			}
			return $value;
		}
		if (\is_array($value)) {
			$class = (string) ($value['class'] ?? '');
			unset($value['class'], $value['id']);
			// An autoloadable class name first, then a Prado3 short name through the class map.
			$resolved = ($class === '' || \class_exists($class)) ? $class : (string) Prado::usingClass($class);
			if ($resolved === '' || !\is_a($resolved, $interface, true) || !\is_a($resolved, TComponent::class, true)) {
				throw new TConfigurationException('privacy_component_invalid', $class, $interface);
			}
			$component = Prado::createComponent($resolved);
			foreach ($value as $property => $propertyValue) {
				$component->setSubProperty($property, $propertyValue);
			}
			return $component;
		}
		$id = \trim((string) $value);
		$module = Prado::getApplication()->getModule($id);
		if (!($module instanceof $interface)) {
			throw new TConfigurationException('privacy_component_invalid', $id, $interface);
		}
		return $module;
	}
}
