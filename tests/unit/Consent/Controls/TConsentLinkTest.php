<?php

namespace belisoful\Privacy\Test\Unit\Consent\Controls;

use belisoful\Privacy\Consent\Controls\TConsentLink;
use belisoful\Privacy\Consent\Controls\TConsentPreferences;
use belisoful\Privacy\Consent\Region\THeaderRegionResolver;
use Prado\Exceptions\TConfigurationException;
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

	private function preferences(): TConsentPreferences
	{
		$preferences = new TConsentPreferences();
		$preferences->setID('Prefs');
		$preferences->setDefaultStyle(false);
		return $preferences;
	}

	public function testPreferencesLink()
	{
		$link = $this->link();
		$preferences = $this->preferences();
		$page = $this->page($link, $preferences)->runPreRender();
		$this->assertSame('preferences', $link->getMode());
		$this->assertSame('Privacy choices', $link->getText());
		$this->assertFalse($link->getCausesValidation());
		$this->assertTrue($link->getIsShown());
		$this->assertSame([$link, $preferences], $this->module->getConsentControls());
		$this->assertTrue($page->getClientScript()->isScriptFileRegistered('prado-consent'));
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
		$page = $this->page($link, $this->preferences())->runPreRender();
		$html = $page->renderForm();
		$this->assertStringContainsString('href="/privacy-settings"', $html);
	}

	public function testWithoutPreferencesItIsAPlainLink()
	{
		$link = $this->link();
		$link->setPreferencesUrl('/privacy-settings');
		$link->setCssClass('footer-link');
		$page = $this->page($link)->runPreRender();
		$html = $page->renderForm();
		$this->assertStringContainsString('<a id="Link" class="footer-link" data-consent-link="preferences" href="/privacy-settings">Privacy choices</a>', $html);
		$link->onClick(new TEventParameter());
		$this->assertFalse($this->module->getPreferencesOpen(), 'nothing to open');
		$this->assertSame([], $page->clientFunctions());
	}

	public function testWithoutPreferencesOrUrlRenderingThrows()
	{
		$link = $this->link();
		$page = $this->page($link)->runPreRender();
		try {
			$page->renderForm();
			$this->fail('refused');
		} catch (TConfigurationException $e) {
			$this->assertSame('privacy_preferences_unreachable', $e->getErrorCode());
			$this->assertStringContainsString('TConsentLink "Link"', $e->getMessage());
		}
	}

	public function testTheTextIsEncodedUnlessEncodeIsOff()
	{
		$link = $this->link();
		$this->assertTrue($link->getEncode());
		$link->setText('Cookies <b>& more</b>');
		$page = $this->page($link, $this->preferences())->runPreRender();
		// THttpUtility::htmlEncode() encodes < > " and leaves & for entities.
		$this->assertStringContainsString('>Cookies &lt;b&gt;& more&lt;/b&gt;</a>', $page->renderForm());
		$link->setEncode('false');
		$this->assertFalse($link->getEncode());
		$this->assertStringContainsString('>Cookies <b>& more</b></a>', $page->renderForm());
	}

	public function testPreferencesUrlMustBeHttpOrRelative()
	{
		$link = $this->link();
		$link->setPreferencesUrl('https://example.com/privacy');
		$this->assertSame('https://example.com/privacy', $link->getPreferencesUrl());
		try {
			$link->setPreferencesUrl('javascript:alert(document.cookie)');
			$this->fail('refused');
		} catch (TInvalidDataValueException $e) {
			$this->assertSame('privacy_url_scheme_invalid', $e->getErrorCode());
			$this->assertStringContainsString('"javascript:alert(document.cookie)"', $e->getMessage());
		}
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
		$page = $this->page($link, $this->preferences())->runPreRender()->inForm();
		$clicked = 0;
		$link->attachEventHandler('OnClick', function () use (&$clicked) {
			$clicked++;
		});
		$link->onClick(new TEventParameter());
		$this->assertTrue($this->module->getPreferencesOpen());
		$this->assertSame(1, $clicked);
		$this->assertSame([['PradoConsent.open' => ['Prefs_Heading', 'Link']]], $page->clientFunctions());
		$called = 0;
		$link->attachEventHandler('OnCallback', function () use (&$called) {
			$called++;
		});
		$param = $this->callbackParam();
		$link->onCallback($param);
		$this->assertSame(1, $called);
		$this->assertCount(2, $param->writers);
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
