<?php

/**
 * TMethodRetentionPurger class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Retention;

use Prado\Exceptions\TConfigurationException;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\Prado;
use Prado\TComponent;
use Prado\TPropertyValue;

/**
 * TMethodRetentionPurger class.
 *
 * TMethodRetentionPurger calls a module's method with the cutoff, in the `moduleId->method` form
 * `TCronModule` uses. The method takes the Unix time and returns the number of items removed.
 *
 * ```xml
 * <purger class="belisoful\Privacy\Retention\TMethodRetentionPurger" Method="uploads->purgeBefore" />
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TMethodRetentionPurger extends TComponent implements IRetentionPurger
{
	/** @var string the module id */
	private string $_moduleId = '';

	/** @var string the method */
	private string $_method = '';

	/**
	 * Calls the module's method with the cutoff.
	 * @param int $before a Unix time
	 * @throws TConfigurationException when the module or the method does not exist
	 * @return int the items the method reports removed
	 */
	public function purge(int $before): int
	{
		$module = Prado::getApplication()->getModule($this->_moduleId);
		if ($module === null || !\method_exists($module, $this->_method)) {
			throw new TConfigurationException('privacy_purger_method_missing', $this->getMethod());
		}
		return (int) $module->{$this->_method}($before);
	}

	/**
	 * @return string the method, as `moduleId->method`
	 */
	public function getMethod(): string
	{
		return $this->_moduleId . '->' . $this->_method;
	}

	/**
	 * @param mixed $value the method, as `moduleId->method`
	 * @throws TInvalidDataValueException when the value is not in that form
	 * @return static the purger
	 */
	public function setMethod($value): static
	{
		$value = \trim(TPropertyValue::ensureString($value));
		if (!\preg_match('/^([^\s>-][^\s]*)->([A-Za-z_][A-Za-z0-9_]*)$/', $value, $match)) {
			throw new TInvalidDataValueException('privacy_purger_method_invalid', $value);
		}
		[, $this->_moduleId, $this->_method] = $match;
		return $this;
	}
}
