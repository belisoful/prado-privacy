<?php

namespace belisoful\Privacy\Test\Unit\Consent\Controls;

use belisoful\Privacy\Consent\Controls\TConsentBanner;
use belisoful\Privacy\Consent\Controls\TConsentGate;
use belisoful\Privacy\Consent\Controls\TConsentPreferences;
use Prado\Exceptions\TConfigurationException;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\IO\TTextWriter;
use Prado\Web\UI\THtmlWriter;

class TConsentBannerTest extends ConsentControlTestCase
{
	private function banner(): TConsentBanner
	{
		$banner = new TConsentBanner();
		$banner->setID('Banner');
		$banner->setDefaultStyle(false);
		return $banner;
	}

	private function preferences(): TConsentPreferences
	{
		$preferences = new TConsentPreferences();
		$preferences->setID('Prefs');
		$preferences->setDefaultStyle(false);
		return $preferences;
	}

	public function testAnUndecidedVisitorSeesTheBanner()
	{
		$banner = $this->banner();
		$preferences = $this->preferences();
		$page = $this->page($banner, $preferences)->runPreRender();
		$this->assertSame([$banner, $preferences], $this->module->getConsentControls());
		$this->assertSame($preferences, $banner->getPreferencesControl());
		$this->assertTrue($page->getClientScript()->isScriptFileRegistered('prado-consent'));
		$this->assertTrue($banner->getIsShown());
		$this->assertTrue($banner->Body->getVisible(false));
		$this->assertFalse($banner->PolicyLink->getVisible(false));
		$this->assertFalse($banner->GpcNotice->getVisible(false));
		$this->assertSame('Accept all', $banner->AcceptAll->getText());
		$this->assertSame('Reject all', $banner->RejectAll->getText());
		$this->assertSame('Customize', $banner->Customize->getText());
		$html = $page->renderForm();
		$this->assertStringContainsString('class="prado-consent-banner-inner" role="region" aria-label="Privacy choices"', $html);
		$this->assertStringContainsString(TConsentBanner::TEXTS['Message'], $html);
		$this->assertSame(2, \substr_count($html, 'class="prado-consent-button"'), 'accept and reject share the class');
		$this->assertTrue($banner->Customize->getVisible(false));
		$this->assertFalse($banner->CustomizeLink->getVisible(false));
		$this->assertStringContainsString('id="Banner_Customize"', $html);
	}

	public function testCustomizeLinksToPreferencesUrlOnAPageWithoutPreferences()
	{
		$banner = $this->banner();
		$banner->setPreferencesUrl(' /privacy-settings ');
		$page = $this->page($banner)->runPreRender();
		$this->assertSame('/privacy-settings', $banner->getPreferencesUrl());
		$this->assertNull($banner->getPreferencesControl());
		$html = $page->renderForm();
		$this->assertStringContainsString('<a id="Banner_CustomizeLink" class="prado-consent-button-secondary" href="/privacy-settings">Customize</a>', $html);
		$this->assertFalse($banner->Customize->getVisible(false));
		$banner->customizeClicked($banner->Customize, null);
		$this->assertFalse($this->module->getPreferencesOpen());
		$this->assertTrue($banner->getIsShown(), 'nothing opened, so the banner stays');
		$this->assertSame([], $page->clientFunctions());
	}

	public function testInvisiblePreferencesDoNotCount()
	{
		$banner = $this->banner();
		$banner->setPreferencesUrl('/privacy-settings');
		$preferences = $this->preferences();
		$preferences->setVisible(false);
		$this->page($banner, $preferences)->runPreRender();
		$this->assertNull($banner->getPreferencesControl());
	}

	public function testAShownBannerWithoutPreferencesNeedsPreferencesUrl()
	{
		$banner = $this->banner();
		$page = $this->page($banner)->runPreRender();
		try {
			$page->renderForm();
			$this->fail('refused');
		} catch (TConfigurationException $e) {
			$this->assertSame('privacy_preferences_unreachable', $e->getErrorCode());
			$this->assertStringContainsString('TConsentBanner "Banner"', $e->getMessage());
		}
	}

	public function testAHiddenBannerWithoutPreferencesRenders()
	{
		$banner = $this->banner();
		$this->module->acceptAll();
		$html = $this->page($banner)->runPreRender()->renderForm();
		$this->assertStringContainsString('id="Banner_Panel"', $html);
		$this->assertStringNotContainsString('prado-consent-banner-inner', $html);
	}

	public function testUrlsMustBeHttpOrRelative()
	{
		$banner = $this->banner();
		foreach (['https://example.com/privacy', 'http://example.com/', '//example.com/privacy', '/privacy', '?page=Privacy', 'privacy.html', '#privacy', ''] as $url) {
			$banner->setPolicyUrl($url);
			$this->assertSame($url, $banner->getPolicyUrl());
			$banner->setPreferencesUrl($url);
			$this->assertSame($url, $banner->getPreferencesUrl());
		}
		foreach (['javascript:alert(1)', ' JavaScript:alert(1)', "java\tscript:alert(1)", 'data:text/html,<p>', 'vbscript:x'] as $url) {
			foreach (['setPolicyUrl', 'setPreferencesUrl'] as $setter) {
				try {
					$banner->{$setter}($url);
					$this->fail($setter . ' refused ' . $url);
				} catch (TInvalidDataValueException $e) {
					$this->assertSame('privacy_url_scheme_invalid', $e->getErrorCode());
				}
			}
		}
	}

	public function testTheButtons()
	{
		$banner = $this->banner();
		$page = $this->page($banner, $this->preferences());
		$banner->acceptAllClicked($banner->AcceptAll, null);
		$this->assertSame('banner', $this->module->getRecord()->getSource());
		$this->assertTrue($this->module->isGranted('analytics'));
		$this->assertFalse($banner->getIsShown());
		$banner->rejectAllClicked($banner->RejectAll, null);
		$this->assertSame('denied', $this->module->getCategoryConsent('analytics'));
		$this->module->withdraw();
		$this->assertTrue($banner->getIsShown());
		$banner->customizeClicked($banner->Customize, null);
		$this->assertTrue($this->module->getPreferencesOpen());
		$this->assertFalse($banner->getIsShown(), 'the preferences replace the banner');
		$this->assertSame([
			['PradoConsent.chosen' => []],
			['PradoConsent.chosen' => []],
			['PradoConsent.open' => ['Prefs_Heading', 'Banner_Customize']],
		], $page->clientFunctions());
	}

	public function testDecidedOrOutOfScopeHidesIt()
	{
		$banner = $this->banner();
		$this->module->setRegionResolver(new \belisoful\Privacy\Consent\Region\THeaderRegionResolver());
		$this->request->headers['CF-IPCountry'] = 'JP';
		$this->page($banner)->runPreRender();
		$this->assertFalse($banner->Body->getVisible(false));
	}

	public function testPolicyLinkAndGpcNotice()
	{
		$banner = $this->banner();
		$banner->setPolicyUrl(' /privacy ');
		$this->request->headers['Sec-GPC'] = '1';
		$this->page($banner)->runPreRender();
		$this->assertSame('/privacy', $banner->getPolicyUrl());
		$this->assertTrue($banner->PolicyLink->getVisible(false));
		$this->assertSame('/privacy', $banner->PolicyLink->getNavigateUrl());
		$this->assertSame('Privacy policy', $banner->PolicyLink->getText());
		$this->assertTrue($banner->GpcNotice->getVisible(false));
		$this->assertSame(TConsentBanner::TEXTS['GpcMessage'], $banner->GpcText->getText());
	}

	public function testTexts()
	{
		$banner = $this->banner();
		foreach (['Message', 'AcceptText', 'RejectText', 'CustomizeText', 'PolicyText', 'GpcMessage', 'Label'] as $property) {
			$this->assertSame(TConsentBanner::TEXTS[$property], $banner->{'get' . $property}());
			$banner->{'set' . $property}('custom ' . $property);
			$this->assertSame('custom ' . $property, $banner->{'get' . $property}());
			$banner->{'set' . $property}('');
			$this->assertSame(TConsentBanner::TEXTS[$property], $banner->{'get' . $property}());
		}
	}

	public function testRefreshRendersThePanelAndEveryRegisteredControl()
	{
		$banner = $this->banner();
		$gate = new TConsentGate();
		$gate->setCategory('analytics');
		$this->page($banner, $gate, $this->preferences())->runPreRender()->inForm();
		$writer = new THtmlWriter(new TTextWriter());
		$banner->refreshConsent($writer);
		$this->assertStringContainsString('id="Banner_Panel"', $writer->flush());
		$param = $this->callbackParam();
		$banner->refreshConsentControls($banner->AcceptAll, $param);
		$this->assertCount(3, $param->writers);
		$this->assertStringContainsString('data-consent-category="analytics"', $param->writers[1]->flush());
	}

	public function testDefaultStyleRegistersTheStylesheet()
	{
		$banner = $this->banner();
		$this->assertFalse($banner->getDefaultStyle());
		$page = $this->page($banner)->runPreRender();
		$this->assertFalse($page->getClientScript()->isStyleSheetFileRegistered('prado-consent'));
		$styled = new TConsentBanner();
		$this->assertTrue($styled->getDefaultStyle());
		$page = $this->page($styled)->runPreRender();
		$this->assertTrue($page->getClientScript()->isStyleSheetFileRegistered('prado-consent'));
	}

	public function testConsentModuleId()
	{
		$banner = $this->banner();
		$banner->setConsentModuleID(' privacy ');
		$this->assertSame('privacy', $banner->getConsentModuleID());
		$this->assertSame($this->module, $banner->getConsentModule());
		$banner->setConsentModuleID('missing');
		try {
			$banner->getConsentModule();
			$this->fail('refused');
		} catch (TConfigurationException $e) {
			$this->assertSame('privacy_consent_module_missing', $e->getErrorCode());
		}
	}
}
