<?php

use belisoful\Privacy\Consent\TConsentModule;
use Prado\Web\UI\TPage;

/**
 * Answers the consent log entries of the visitor's consent id as JSON.
 */
class Log extends TPage
{
	public function onLoad($param)
	{
		parent::onLoad($param);
		$this->getResponse()->setContentType('application/json');
	}

	public function render($writer)
	{
		$module = TConsentModule::findModule();
		$record = $module->getStoredRecord();
		$writer->write(json_encode($record === null ? [] : $module->getLog()->findByConsentId($record->getId())));
	}
}
