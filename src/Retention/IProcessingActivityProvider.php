<?php

/**
 * IProcessingActivityProvider interface file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Retention;

/**
 * IProcessingActivityProvider interface.
 *
 * IProcessingActivityProvider is implemented by a module that processes personal data, so it
 * declares its processing activities into `TProcessingRegistry` (GDPR Art. 30). A component that
 * is not a module declares through the global event `fxGetProcessingActivities` instead.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
interface IProcessingActivityProvider
{
	/**
	 * @return TProcessingActivity[] the activities the module carries out
	 */
	public function getProcessingActivities(): array;
}
