<?php

namespace belisoful\Privacy\Test\Unit\Consent\Controls;

use belisoful\Privacy\Consent\Controls\TConsentPreferences;
use belisoful\Privacy\Consent\TConsentCategory;
use Prado\IO\TTextWriter;
use Prado\Web\UI\THtmlWriter;

class TConsentPreferencesTest extends ConsentControlTestCase
{
	private function preferences(): TConsentPreferences
	{
		$preferences = new TConsentPreferences();
		$preferences->setID('Prefs');
		$preferences->setDefaultStyle(false);
		return $preferences;
	}

	public function testACheckboxPerCategory()
	{
		$this->module->addCategory(new TConsentCategory('video'));
		$preferences = $this->preferences();
		$this->page($preferences);
		$boxes = $preferences->getCheckBoxes();
		$this->assertSame(['necessary', 'analytics', 'marketing', 'functional', 'personalization', 'video'], \array_keys($boxes));
		$this->assertSame('Category_analytics', $boxes['analytics']->getID());
		$this->assertSame('Analytics', $boxes['analytics']->getText());
		$this->assertFalse($boxes['necessary']->getEnabled());
		$this->assertTrue($boxes['video']->getEnabled());
		$this->assertSame([$preferences], $this->module->getConsentControls());
	}

	public function testClosedUntilOpened()
	{
		$preferences = $this->preferences();
		$page = $this->page($preferences)->runPreRender();
		$this->assertFalse($preferences->getIsShown());
		$this->assertFalse($preferences->Body->getVisible(false));
		$this->assertStringNotContainsString('prado-consent-preferences-inner', $page->renderForm());
		$this->module->setPreferencesOpen(true);
		$page->runPreRender();
		$html = $page->renderForm();
		$this->assertStringContainsString('aria-label="Privacy preferences"', $html);
		$this->assertStringContainsString('data-consent-category="marketing"', $html);
		$this->assertStringContainsString('<p class="prado-consent-description">Measuring visits and use of the site.</p>', $html);
		$this->assertTrue($preferences->Close->getVisible(false));
	}

	public function testAlwaysVisibleHasNoCloseButton()
	{
		$preferences = $this->preferences();
		$preferences->setAlwaysVisible('true');
		$this->page($preferences)->runPreRender();
		$this->assertTrue($preferences->getAlwaysVisible());
		$this->assertTrue($preferences->getIsShown());
		$this->assertFalse($preferences->Close->getVisible(false));
	}

	public function testCheckboxesFollowTheConsent()
	{
		$this->module->setConsent(['analytics' => true]);
		$preferences = $this->preferences();
		$this->page($preferences)->runPreRender();
		$boxes = $preferences->getCheckBoxes();
		$this->assertTrue($boxes['necessary']->getChecked());
		$this->assertTrue($boxes['analytics']->getChecked());
		$this->assertFalse($boxes['marketing']->getChecked());
	}

	public function testSaveRecordsTheCheckboxesAndCloses()
	{
		$preferences = $this->preferences();
		$this->page($preferences);
		$this->module->setPreferencesOpen(true);
		$preferences->getCheckBoxes()['functional']->setChecked(true);
		$preferences->saveClicked($preferences->Save, null);
		$record = $this->module->getRecord();
		$this->assertSame('preferences', $record->getSource());
		$this->assertSame('granted', $record->getState('functional'));
		$this->assertSame('denied', $record->getState('analytics'));
		$this->assertSame('granted', $record->getState('necessary'), 'a disabled checkbox cannot deny a required category');
		$this->assertFalse($this->module->getPreferencesOpen());
	}

	public function testAcceptRejectAndClose()
	{
		$preferences = $this->preferences();
		$this->page($preferences);
		$this->module->setPreferencesOpen(true);
		$preferences->acceptAllClicked($preferences->AcceptAll, null);
		$this->assertTrue($this->module->isGranted('marketing'));
		$this->assertFalse($this->module->getPreferencesOpen());
		$this->module->setPreferencesOpen(true);
		$preferences->rejectAllClicked($preferences->RejectAll, null);
		$this->assertFalse($this->module->isGranted('marketing'));
		$this->assertFalse($this->module->getPreferencesOpen());
		$this->module->setPreferencesOpen(true);
		$preferences->closeClicked($preferences->Close, null);
		$this->assertFalse($this->module->getPreferencesOpen());
	}

	public function testTexts()
	{
		$preferences = $this->preferences();
		foreach (['Title', 'SaveText', 'AcceptText', 'RejectText', 'CloseText'] as $property) {
			$this->assertSame(TConsentPreferences::TEXTS[$property], $preferences->{'get' . $property}());
			$preferences->{'set' . $property}('custom');
			$this->assertSame('custom', $preferences->{'get' . $property}());
			$preferences->{'set' . $property}('');
		}
		$this->page($preferences)->runPreRender();
		$this->assertSame('Save choices', $preferences->Save->getText());
		$this->assertSame('Privacy preferences', $preferences->TitleText->getText());
	}

	public function testRefreshRendersThePanel()
	{
		$preferences = $this->preferences();
		$this->page($preferences)->runPreRender()->inForm();
		$writer = new THtmlWriter(new TTextWriter());
		$preferences->refreshConsent($writer);
		$this->assertStringContainsString('id="Prefs_Panel"', $writer->flush());
	}
}
