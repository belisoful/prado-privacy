<?php

namespace belisoful\Privacy\Test\Unit;

use Prado\IO\TTextWriter;
use Prado\Web\UI\THtmlWriter;
use Prado\Web\UI\TPage;
use Prado\Web\UI\TForm;

/**
 * A TPage whose lifecycle a unit test drives step by step: the controls go in a form, and
 * runInit(), runLoad() and runPreRender() expose the protected recursive steps.
 */
class LifecyclePage extends TPage
{
	public TForm $form;

	public function __construct(array $controls = [])
	{
		parent::__construct();
		$this->form = new TForm();
		$this->form->setID('Form');
		$this->getControls()->add($this->form);
		foreach ($controls as $control) {
			$this->form->getControls()->add($control);
		}
	}

	public function runInit(): static
	{
		$this->initRecursive();
		return $this;
	}

	public function runLoad(): static
	{
		$this->loadRecursive();
		return $this;
	}

	public function runPreRender(): static
	{
		$this->preRenderRecursive();
		return $this;
	}

	/**
	 * Marks the page as rendering its form, so a control rendered on its own (a callback refresh)
	 * passes ensureRenderInForm().
	 */
	public function inForm(): static
	{
		$this->_inFormRender = true;
		return $this;
	}

	public function renderForm(): string
	{
		// A page renders its form once; a test renders it again after changing the consent.
		$this->_formRendered = false;
		$writer = new THtmlWriter(new TTextWriter());
		$this->form->renderControl($writer);
		return $writer->flush();
	}
}
