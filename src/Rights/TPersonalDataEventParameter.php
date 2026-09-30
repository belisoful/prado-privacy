<?php

/**
 * TPersonalDataEventParameter class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Rights;

use Prado\TEventParameter;

/**
 * TPersonalDataEventParameter class.
 *
 * TPersonalDataEventParameter carries a built-in provider's events to the application, which
 * owns the data the provider cannot reach itself:
 *
 * | Event | The handler |
 * |---|---|
 * | `onExportUser` | adds fields to `Data` |
 * | `onEraseUser` | deletes the user and records it in `Result` (`addErased()`, `addRetained()`) |
 * | `onRectifyUser` | applies `Changes` and calls `setApplied(true)` |
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TPersonalDataEventParameter extends TEventParameter
{
	/** @var array the exported data */
	private array $_data = [];

	/** @var bool whether the changes were applied */
	private bool $_applied = false;

	/**
	 * @param TDataSubject $_subject the subject
	 * @param ?TErasureResult $_result the erasure result, for `onEraseUser`
	 * @param array $_changes the corrections, for `onRectifyUser`
	 */
	public function __construct(private TDataSubject $_subject, private ?TErasureResult $_result = null, private array $_changes = [])
	{
		parent::__construct($_subject);
	}

	/**
	 * @return TDataSubject the subject
	 */
	public function getSubject(): TDataSubject
	{
		return $this->_subject;
	}

	/**
	 * @return array the exported data
	 */
	public function getData(): array
	{
		return $this->_data;
	}

	/**
	 * @param array $value the exported data
	 */
	public function setData(array $value): void
	{
		$this->_data = $value;
	}

	/**
	 * @return ?TErasureResult the erasure result, for `onEraseUser`
	 */
	public function getResult(): ?TErasureResult
	{
		return $this->_result;
	}

	/**
	 * @return array the corrections, for `onRectifyUser`
	 */
	public function getChanges(): array
	{
		return $this->_changes;
	}

	/**
	 * @return bool whether a handler applied the changes
	 */
	public function getApplied(): bool
	{
		return $this->_applied;
	}

	/**
	 * @param bool $value whether a handler applied the changes
	 */
	public function setApplied(bool $value): void
	{
		$this->_applied = $value;
	}
}
