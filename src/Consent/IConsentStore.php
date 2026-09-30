<?php

/**
 * IConsentStore interface file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Consent;

/**
 * IConsentStore interface.
 *
 * IConsentStore keeps the visitor's current choice between requests. `TConsentModule` reads it
 * once per request, writes it when the visitor chooses, and clears it on a withdrawal. A store
 * returns what it holds; the module decides whether the record's version and expiry make it
 * current.
 *
 * `TCookieConsentStore` is the default; `TSessionConsentStore` and `TDbConsentStore` are the
 * alternatives.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
interface IConsentStore
{
	/**
	 * @return ?TConsentRecord the stored choice; null when there is none or it cannot be read
	 */
	public function load(): ?TConsentRecord;

	/**
	 * Stores a choice, replacing the previous one.
	 * @param TConsentRecord $record the choice
	 */
	public function save(TConsentRecord $record): void;

	/**
	 * Removes the stored choice.
	 */
	public function clear(): void;
}
