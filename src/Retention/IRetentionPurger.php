<?php

/**
 * IRetentionPurger interface file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Retention;

/**
 * IRetentionPurger interface.
 *
 * IRetentionPurger removes one kind of data older than a time, for a `TRetentionPolicy`. The
 * consent log (`IConsentLog`), the request log (`IPrivacyRequestLog`) and `TDbConsentStore` are
 * purgers; `TDbRetentionPurger` purges any table by a time column, and `TMethodRetentionPurger`
 * calls a module's method.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 */
interface IRetentionPurger
{
	/**
	 * Removes the data older than a time.
	 * @param int $before a Unix time; data from before it is removed
	 * @return int the number of items removed
	 */
	public function purge(int $before): int;
}
