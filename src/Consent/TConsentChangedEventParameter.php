<?php

/**
 * TConsentChangedEventParameter class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Consent;

use Prado\TEventParameter;

/**
 * TConsentChangedEventParameter class.
 *
 * TConsentChangedEventParameter carries `TConsentModule::onConsentChanged`: the effective consent
 * before and after the change (category → `granted`, `denied` or `undecided`), the record the
 * change stored (null after a withdrawal), and the source (`banner`, `preferences`, `api`,
 * `withdraw`). `getChanged()` lists the categories whose state differs.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 */
class TConsentChangedEventParameter extends TEventParameter
{
	/** @var array<string, string> the consent before the change */
	private array $_previous;

	/** @var array<string, string> the consent after the change */
	private array $_current;

	/** @var ?TConsentRecord the record stored */
	private ?TConsentRecord $_record;

	/** @var string where the change came from */
	private string $_source;

	/**
	 * @param array<string, string> $previous the consent before the change
	 * @param array<string, string> $current the consent after the change
	 * @param ?TConsentRecord $record the record stored; null after a withdrawal
	 * @param string $source where the change came from
	 */
	public function __construct(array $previous, array $current, ?TConsentRecord $record, string $source)
	{
		$this->_previous = $previous;
		$this->_current = $current;
		$this->_record = $record;
		$this->_source = $source;
		parent::__construct($current);
	}

	/**
	 * @return array<string, string> category => state before the change
	 */
	public function getPrevious(): array
	{
		return $this->_previous;
	}

	/**
	 * @return array<string, string> category => state after the change
	 */
	public function getCurrent(): array
	{
		return $this->_current;
	}

	/**
	 * @return array<string, string> category => new state, for the categories whose state changed
	 */
	public function getChanged(): array
	{
		return \array_diff_assoc($this->_current, $this->_previous);
	}

	/**
	 * @return ?TConsentRecord the record stored; null after a withdrawal
	 */
	public function getRecord(): ?TConsentRecord
	{
		return $this->_record;
	}

	/**
	 * @return string where the change came from
	 */
	public function getSource(): string
	{
		return $this->_source;
	}
}
