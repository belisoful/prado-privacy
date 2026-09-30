<?php

use belisoful\Privacy\Consent\TConsentModule;
use Prado\Web\UI\TPage;

/**
 * Shows the module's consent as JSON, so the specs can read it without parsing cookies.
 */
class Home extends TPage
{
	public function getConsentJson(): string
	{
		$module = TConsentModule::findModule();
		return htmlspecialchars(json_encode([
			'consent' => $module->getConsent(),
			'decided' => $module->getIsDecided(),
			'mode' => $module->getMode(),
			'region' => $module->getRegion(),
			'record' => $module->getRecord()?->toArray(),
		]));
	}

	public function refreshState($sender, $param): void
	{
		$this->State->render($param->getNewWriter());
	}
}
