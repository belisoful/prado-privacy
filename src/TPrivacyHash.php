<?php

/**
 * TPrivacyHash class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy;

use Prado\Prado;

/**
 * TPrivacyHash class.
 *
 * TPrivacyHash turns an identifier of a person (an IP address, a user name, a user agent) into
 * an HMAC-SHA256 hex digest. Its key is derived from the application's validation key
 * (`TSecurityManager::getValidationKey()`) as `HMAC-SHA256(validation key, "prado-privacy:hash")`,
 * so the digests share no key with PRADO's own signatures. The same identifier gives the same
 * digest, so logs link entries of one person; the digest cannot be reversed by enumerating values
 * without the key, which a plain SHA-256 of an IPv4 address (2³² values) can.
 *
 * Every server of an application needs the same, explicitly set validation key. Changing it
 * breaks the link between old and new digests.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 */
class TPrivacyHash
{
	/** The label the hash key is derived with. */
	private const KEY_LABEL = 'prado-privacy:hash';

	/** The purpose label of a user name. */
	public const SUBJECT = 'subject';

	/** The purpose label of an IP address. */
	public const IP = 'ip';

	/** The purpose label of a user agent. */
	public const USER_AGENT = 'user-agent';

	/** The purpose label of a cache key. */
	public const CACHE = 'cache';

	/**
	 * Returns the keyed digest of an identifier.
	 * @param string $value the identifier
	 * @param string $purpose a label that separates digests of one value used for different purposes
	 * @return string the 64-character hex digest
	 */
	public static function hash(string $value, string $purpose = ''): string
	{
		$key = \hash_hmac('sha256', self::KEY_LABEL, (string) Prado::getApplication()->getSecurityManager()->getValidationKey(), true);
		return \hash_hmac('sha256', $purpose . "\0" . $value, $key);
	}
}
