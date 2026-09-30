<?php

/**
 * TErasureResult class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Rights;

use Prado\TComponent;

/**
 * TErasureResult class.
 *
 * TErasureResult is a provider's answer to an erasure: the number of items `Erased`, the items
 * `Retained` with the legal reason for each group (GDPR Art. 17(3): a legal obligation, legal
 * claims, proof of consent), and `Errors`. It holds no personal data, so the request log can
 * store it.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TErasureResult extends TComponent
{
	/** @var int items erased */
	private int $_erased = 0;

	/** @var array<int, array{count: int, reason: string}> items retained, by reason */
	private array $_retained = [];

	/** @var string[] errors */
	private array $_errors = [];

	/**
	 * @param int $erased items erased
	 */
	public function __construct(int $erased = 0)
	{
		$this->_erased = $erased;
		parent::__construct();
	}

	/**
	 * @return int items erased
	 */
	public function getErased(): int
	{
		return $this->_erased;
	}

	/**
	 * @param int $count items erased
	 * @return static the result
	 */
	public function addErased(int $count): static
	{
		$this->_erased += $count;
		return $this;
	}

	/**
	 * @return array<int, array{count: int, reason: string}> items retained, by reason
	 */
	public function getRetained(): array
	{
		return $this->_retained;
	}

	/**
	 * @param int $count items retained
	 * @param string $reason why they are retained, with the provision (`proof of consent, GDPR Art. 7(1)`)
	 * @return static the result
	 */
	public function addRetained(int $count, string $reason): static
	{
		$this->_retained[] = ['count' => $count, 'reason' => $reason];
		return $this;
	}

	/**
	 * @return string[] errors
	 */
	public function getErrors(): array
	{
		return $this->_errors;
	}

	/**
	 * @param string $error an error, without personal data
	 * @return static the result
	 */
	public function addError(string $error): static
	{
		$this->_errors[] = $error;
		return $this;
	}

	/**
	 * @return array{erased: int, retained: array, errors: string[]} the result, as the request log stores it
	 */
	public function toArray(): array
	{
		return ['erased' => $this->_erased, 'retained' => $this->_retained, 'errors' => $this->_errors];
	}
}
