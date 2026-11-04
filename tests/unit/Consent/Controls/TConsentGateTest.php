<?php

namespace belisoful\Privacy\Test\Unit\Consent\Controls;

use belisoful\Privacy\Consent\Controls\TConsentGate;
use Prado\Exceptions\TConfigurationException;
use Prado\IO\TTextWriter;
use Prado\Web\UI\TControl;
use Prado\Web\UI\THtmlWriter;
use Prado\Web\UI\TTemplate;
use Prado\Web\UI\WebControls\TLiteral;

class TConsentGateTest extends ConsentControlTestCase
{
	private TControl $tracker;

	private function gate(bool $withTemplate = true): TConsentGate
	{
		$gate = new TConsentGate();
		$gate->setID('Gate');
		$gate->setCategory(' Functional ');
		$gate->addParsedObject('<iframe id="embed"></iframe>');
		$literal = new TLiteral();
		$literal->setText('<span id="more"></span>');
		$gate->addParsedObject($literal);
		$this->tracker = new class () extends TControl {
			public bool $preRendered = false;

			public function onPreRender($param)
			{
				parent::onPreRender($param);
				$this->preRendered = true;
			}
		};
		$gate->addParsedObject($this->tracker);
		if ($withTemplate) {
			$gate->setDeniedTemplate(new TTemplate('<p id="denied">Needs consent</p>', __DIR__));
		}
		return $gate;
	}

	public function testDeniedRendersOnlyTheDeniedTemplate()
	{
		$gate = $this->gate();
		$html = $this->page($gate)->runPreRender()->renderForm();
		$this->assertSame('functional', $gate->getCategory());
		$this->assertInstanceOf(TTemplate::class, $gate->getDeniedTemplate());
		$this->assertFalse($gate->getIsGranted());
		$this->assertStringContainsString('data-consent-category="functional"', $html);
		$this->assertStringContainsString('data-consent-state="undecided"', $html);
		$this->assertStringContainsString('id="denied"', $html);
		$this->assertStringNotContainsString('id="embed"', $html);
		$this->assertStringNotContainsString('id="more"', $html);
		$this->assertFalse($this->tracker->preRendered, 'denied content is not pre-rendered: it cannot register a script');
		$this->assertSame([$gate], $this->module->getConsentControls());
	}

	public function testGrantedRendersOnlyTheContent()
	{
		$this->module->acceptAll();
		$gate = $this->gate();
		$page = $this->page($gate)->runPreRender();
		$html = $page->renderForm();
		$this->assertTrue($gate->getIsGranted());
		$this->assertStringContainsString('data-consent-state="granted"', $html);
		$this->assertStringContainsString('id="embed"', $html);
		$this->assertStringContainsString('id="more"', $html);
		$this->assertStringNotContainsString('id="denied"', $html);
		$this->assertTrue($this->tracker->preRendered);
		$html = $page->renderForm();
		$this->assertStringContainsString('id="embed"', $html, 'the denied container is restored for the next render');
	}

	public function testWithoutADeniedTemplateNothingRendersWhileDenied()
	{
		$gate = $this->gate(false);
		$html = $this->page($gate)->runPreRender()->renderForm();
		$this->assertNull($gate->getDeniedTemplate());
		$this->assertStringContainsString('id="Gate"', $html);
		$this->assertStringNotContainsString('id="embed"', $html);
	}

	public function testRefreshRendersTheGate()
	{
		$gate = $this->gate();
		$this->page($gate)->runPreRender();
		$writer = new THtmlWriter(new TTextWriter());
		$gate->refreshConsent($writer);
		$this->assertStringContainsString('id="denied"', $writer->flush());
	}

	public function testACategoryIsRequired()
	{
		$gate = new TConsentGate();
		$gate->setDeniedTemplate(null);
		$this->expectException(TConfigurationException::class);
		$this->page($gate);
	}
}
