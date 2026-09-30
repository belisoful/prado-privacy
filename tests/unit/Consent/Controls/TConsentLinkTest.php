<?php

namespace belisoful\Privacy\Test\Unit\Consent\Controls;

use belisoful\Privacy\Consent\Controls\TConsentLink;
use belisoful\Privacy\Consent\Region\THeaderRegionResolver;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\IO\TTextWriter;
use Prado\TEventParameter;
use Prado\Web\UI\THtmlWriter;

class TConsentLinkTest extends ConsentControlTestCase
{
	private function link(string $mode = 'preferences'): TConsentLink
	{
		$link = new TConsentLink();
		$link->setID('Link');
		$link->setMode($mode);
		return $link;
	}

	public function testPreferencesLink()
	{
		$link = $this->link();
		$page = $this->page($link)->runPreRender();
		$this->assertSame('preferences', $link->getMode());
		$this->assertSame('Privacy choices', $link->getText());
		$this->assertFalse($link->getCausesValidation());
		$this->assertTrue($link->getIsShown());
		$this->assertSame([$link], $this->module->getConsentControls());
		$html = $page->renderForm();
		$this->assertStringContainsString('data-consent-link="preferences"', $html);
		$this->assertStringContainsString('href="javascript:;//Link"', $html);
		$link->setText('Cookie settings');
		$this->assertSame('Cookie settings', $link->getText());
	}

	public function testPreferencesUrlIsTheHrefWithoutJavaScript()
	{
		$link = $this->link();
		$link->setPreferencesUrl(' /privacy-settings ');
		$this->assertSame('/privacy-settings', $link->getPreferencesUrl());
		$html = $this->page($link)->runPreRender()->renderForm();
		$this->assertStringContainsString('href="/privacy-settings"', $html);
	}

	public function testOptOutLinkRendersOnlyInOptOutRegions()
	{
		$link = $this->link('OPTOUT');
		$this->module->setRegionResolver(new THeaderRegionResolver());
		$this->page($link)->runPreRender();
		$this->assertSame('Do Not Sell or Share My Personal Information', $link->getText());
		$this->assertFalse($link->getIsShown());
		$this->assertFalse($link->getVisible(false));
		$this->request->headers['CF-IPCountry'] = 'US';
		$this->request->headers['cf-region-code'] = 'CA';
		$this->module->reset();
		$this->assertTrue($link->getIsShown());
	}

	public function testClickOpensThePreferencesAndCallbackRefreshes()
	{
		$link = $this->link();
		$this->page($link)->runPreRender()->inForm();
		$clicked = 0;
		$link->attachEventHandler('OnClick', function () use (&$clicked) {
			$clicked++;
		});
		$link->onClick(new TEventParameter());
		$this->assertTrue($this->module->getPreferencesOpen());
		$this->assertSame(1, $clicked);
		$called = 0;
		$link->attachEventHandler('OnCallback', function () use (&$called) {
			$called++;
		});
		$param = $this->callbackParam();
		$link->onCallback($param);
		$this->assertSame(1, $called);
		$this->assertCount(1, $param->writers);
		$this->assertStringContainsString('id="Link"', $param->writers[0]->flush());
		$writer = new THtmlWriter(new TTextWriter());
		$link->refreshConsent($writer);
		$this->assertStringContainsString('Privacy choices', $writer->flush());
	}

	public function testModeIsValidated()
	{
		$this->expectException(TInvalidDataValueException::class);
		$this->link('sometimes');
	}
}
