<?php

namespace belisoful\Privacy\Test\Unit\Consent;

use belisoful\Privacy\Consent\TConsentCategory;
use PHPUnit\Framework\TestCase;
use Prado\Exceptions\TInvalidDataValueException;

class TConsentCategoryTest extends TestCase
{
	public function testConstructorAndAccessors()
	{
		$category = new TConsentCategory('video', 'Video', 'Players', true);
		$this->assertSame('video', $category->getName());
		$this->assertSame('Video', $category->getTitle());
		$this->assertSame('Players', $category->getDescription());
		$this->assertTrue($category->getRequired());
		$this->assertSame($category, $category->setTitle(' Clips ')->setDescription(' Embeds ')->setRequired('false'));
		$this->assertSame('Clips', $category->getTitle());
		$this->assertSame('Embeds', $category->getDescription());
		$this->assertFalse($category->getRequired());
	}

	public function testTitleFallsBackToTheName()
	{
		$category = new TConsentCategory();
		$this->assertSame('', $category->getName());
		$category->setName(' Social_Media ');
		$this->assertSame('social_media', $category->getName());
		$this->assertSame('Social_media', $category->getTitle());
	}

	public function testNameIsValidated()
	{
		$this->expectException(TInvalidDataValueException::class);
		new TConsentCategory('9lives');
	}

	public function testNameLengthIsLimited()
	{
		(new TConsentCategory())->setName(\str_repeat('a', 32));
		$this->expectException(TInvalidDataValueException::class);
		(new TConsentCategory())->setName(\str_repeat('a', 33));
	}
}
