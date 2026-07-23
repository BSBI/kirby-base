<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\models;

use BSBI\WebBase\models\GlossaryItem;
use PHPUnit\Framework\TestCase;

final class GlossaryItemTest extends TestCase
{
    public function testConstructorSetsProperties(): void
    {
        $item = new GlossaryItem('Bract', '/glossary/bract');

        $this->assertSame('Bract', $item->getTitle());
        $this->assertSame('/glossary/bract', $item->getUrl());
        $this->assertTrue($item->getStatus());
    }

    public function testDefinitionHandling(): void
    {
        $item = new GlossaryItem('Bract', '/glossary/bract');

        $this->assertFalse($item->hasDefinition());
        $this->assertSame('', $item->getDefinition());

        $item->setDefinition('A modified leaf at the base of a flower.');

        $this->assertTrue($item->hasDefinition());
        $this->assertSame('A modified leaf at the base of a flower.', $item->getDefinition());
    }

    public function testDefinitionHtmlHandling(): void
    {
        $item = new GlossaryItem('Bract', '/glossary/bract');

        $this->assertFalse($item->hasDefinitionHtml());
        $this->assertSame('', $item->getDefinitionHtml());

        $item->setDefinitionHtml('<p>A modified leaf, see <a href="/glossary/petiole">petiole</a>.</p>');

        $this->assertTrue($item->hasDefinitionHtml());
        $this->assertSame(
            '<p>A modified leaf, see <a href="/glossary/petiole">petiole</a>.</p>',
            $item->getDefinitionHtml()
        );
    }

    public function testTypeHandling(): void
    {
        $item = new GlossaryItem('Bract', '/glossary/bract');

        $this->assertFalse($item->hasType());
        $this->assertSame('', $item->getType());

        $item->setType('botany');

        $this->assertTrue($item->hasType());
        $this->assertSame('botany', $item->getType());
    }

    public function testSlugHandling(): void
    {
        $item = new GlossaryItem('Bract', '/glossary/bract');

        $this->assertFalse($item->hasSlug());

        $item->setSlug('bract');

        $this->assertTrue($item->hasSlug());
        $this->assertSame('bract', $item->getSlug());
    }

    public function testFluentSetters(): void
    {
        $item = new GlossaryItem('Bract', '/glossary/bract');

        $result = $item
            ->setDefinition('A modified leaf.')
            ->setDefinitionHtml('<p>A modified leaf.</p>')
            ->setType('botany')
            ->setSlug('bract');

        $this->assertSame($item, $result);
    }
}
