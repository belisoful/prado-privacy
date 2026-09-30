<?php

namespace belisoful\Privacy\Test\Unit;

use belisoful\Privacy\Consent\TConsentModule;

/**
 * A TConsentModule whose consent ids are predictable: 000…001, 000…002, …
 */
class ProbeConsentModule extends TConsentModule
{
	public int $generated = 0;

	protected function generateConsentId(): string
	{
		return \sprintf('%032x', ++$this->generated);
	}

	public function realConsentId(): string
	{
		return parent::generateConsentId();
	}
}
