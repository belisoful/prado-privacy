<?php

namespace belisoful\Privacy\Test\Unit\Consent\Controls;

use belisoful\Privacy\Test\Unit\LifecyclePage;
use Prado\Web\UI\ActiveControls\TCallbackClientScript;

/**
 * A LifecyclePage that keeps one callback client, so a test reads the client functions the
 * controls queue for the callback response.
 */
class ConsentTestPage extends LifecyclePage
{
	private ?TCallbackClientScript $_client = null;

	public function getCallbackClient()
	{
		return $this->_client ??= new TCallbackClientScript();
	}

	/**
	 * @return array the queued client functions, as [function => params] entries
	 */
	public function clientFunctions(): array
	{
		return $this->getCallbackClient()->getClientFunctionsToExecute();
	}
}
