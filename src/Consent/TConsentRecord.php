<?php

/**
 * TConsentRecord class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Consent;

use Prado\TComponent;

/**
 * TConsentRecord class.
 *
 * TConsentRecord is one stored choice: the visitor's `Id` (random, 32 hex characters, the link
 * between the stored choice and the consent log), the decision per category (`granted` or
 * `denied`), the policy `Version` it was made under, its `Time` and `Expires` (Unix seconds), and
 * its `Source` (`banner`, `preferences`, `api`, `withdraw`). A record does not change; a new choice
 * is a new record with the same id.
 *
 * `toArray()` and `fromArray()` give the compact form the stores persist:
 * `{"id":…, "v":…, "t":…, "e":…, "s":…, "c":{"analytics":1, "marketing":0}}`.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 */
class TConsentRecord extends TComponent
{
	/** The pattern of a consent id: 32 lowercase hex characters. */
	public const ID_PATTERN = '/^[0-9a-f]{32}$/';

	/** @var string the consent id */
	private string $_id;

	/** @var array<string, string> category => granted|denied */
	private array $_categories;

	/** @var string the policy version */
	private string $_version;

	/** @var int when the choice was made */
	private int $_time;

	/** @var int when the choice expires */
	private int $_expires;

	/** @var string where the choice came from */
	private string $_source;

	/**
	 * @param string $id the consent id
	 * @param array<string, string> $categories category => `granted` or `denied`
	 * @param string $version the policy version
	 * @param int $time when the choice was made, in Unix seconds
	 * @param int $expires when the choice expires, in Unix seconds
	 * @param string $source where the choice came from
	 */
	public function __construct(string $id, array $categories, string $version, int $time, int $expires, string $source = 'api')
	{
		$this->_id = $id;
		$this->_categories = $categories;
		$this->_version = $version;
		$this->_time = $time;
		$this->_expires = $expires;
		$this->_source = $source;
		parent::__construct();
	}

	/**
	 * @return string the consent id
	 */
	public function getId(): string
	{
		return $this->_id;
	}

	/**
	 * @return array<string, string> category => `granted` or `denied`
	 */
	public function getCategories(): array
	{
		return $this->_categories;
	}

	/**
	 * @param string $category the category name
	 * @return ?string `granted` or `denied`; null when the record has no decision for the category
	 */
	public function getState(string $category): ?string
	{
		return $this->_categories[$category] ?? null;
	}

	/**
	 * @return string the policy version the choice was made under
	 */
	public function getVersion(): string
	{
		return $this->_version;
	}

	/**
	 * @return int when the choice was made, in Unix seconds
	 */
	public function getTime(): int
	{
		return $this->_time;
	}

	/**
	 * @return int when the choice expires, in Unix seconds
	 */
	public function getExpires(): int
	{
		return $this->_expires;
	}

	/**
	 * @return string where the choice came from: `banner`, `preferences`, `api` or `withdraw`
	 */
	public function getSource(): string
	{
		return $this->_source;
	}

	/**
	 * @param int $now the current Unix time
	 * @return bool whether the choice has expired at $now
	 */
	public function isExpired(int $now): bool
	{
		return $now >= $this->_expires;
	}

	/**
	 * @return array{id: string, v: string, t: int, e: int, s: string, c: array<string, int>} the compact form
	 */
	public function toArray(): array
	{
		$categories = [];
		foreach ($this->_categories as $category => $state) {
			$categories[$category] = $state === TConsentState::Granted ? 1 : 0;
		}
		return ['id' => $this->_id, 'v' => $this->_version, 't' => $this->_time, 'e' => $this->_expires, 's' => $this->_source, 'c' => $categories];
	}

	/**
	 * Builds a record from its compact form; anything malformed yields null.
	 * @param mixed $data the compact form, as {@see toArray()} returns it
	 * @return ?TConsentRecord the record; null when $data is not a well-formed compact record
	 */
	public static function fromArray(mixed $data): ?TConsentRecord
	{
		if (!\is_array($data) || !\is_string($data['id'] ?? null) || !\preg_match(self::ID_PATTERN, $data['id'])
			|| !\is_string($data['v'] ?? null) || !\is_int($data['t'] ?? null) || !\is_int($data['e'] ?? null)
			|| !\is_string($data['s'] ?? null) || !\is_array($data['c'] ?? null)) {
			return null;
		}
		$categories = [];
		foreach ($data['c'] as $category => $state) {
			if (!\is_string($category) || !\preg_match(TConsentCategory::NAME_PATTERN, $category)) {
				return null;
			}
			$categories[$category] = $state ? TConsentState::Granted : TConsentState::Denied;
		}
		return new self($data['id'], $categories, $data['v'], $data['t'], $data['e'], $data['s']);
	}
}
