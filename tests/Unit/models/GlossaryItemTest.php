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

    public function testExtendedContentHtmlHandling(): void
    {
        $item = new GlossaryItem('Bract', '/glossary/bract');

        $this->assertFalse($item->hasExtendedContentHtml());
        $this->assertSame('', $item->getExtendedContentHtml());

        $item->setExtendedContentHtml('<p>Bracts occur in many families.</p>');

        $this->assertTrue($item->hasExtendedContentHtml());
        $this->assertSame('<p>Bracts occur in many families.</p>', $item->getExtendedContentHtml());
    }

    public function testUuidHandling(): void
    {
        $item = new GlossaryItem('Bract', '/glossary/bract');

        $this->assertFalse($item->hasUuid());
        $this->assertSame('', $item->getUuid());

        $item->setUuid('page://bract-uuid');

        $this->assertTrue($item->hasUuid());
        $this->assertSame('page://bract-uuid', $item->getUuid());
    }

    public function testPanelUrlHandling(): void
    {
        $item = new GlossaryItem('Bract', '/glossary/bract');

        $this->assertFalse($item->hasPanelUrl());
        $this->assertSame('', $item->getPanelUrl());

        $item->setPanelUrl('/panel/pages/glossary+bract');

        $this->assertTrue($item->hasPanelUrl());
        $this->assertSame('/panel/pages/glossary+bract', $item->getPanelUrl());
    }

    public function testSlugHandling(): void
    {
        $item = new GlossaryItem('Bract', '/glossary/bract');

        $this->assertFalse($item->hasSlug());

        $item->setSlug('bract');

        $this->assertTrue($item->hasSlug());
        $this->assertSame('bract', $item->getSlug());
    }

    public function testGetLinkHtmlRendersEnrichedAnchor(): void
    {
        // used by the glossary/glossary-link snippet so hand-written template
        // content can carry glossary links with the same tooltip behaviour
        $item = (new GlossaryItem('Bract', '/glossary/bract'))
            ->setDefinition('A modified leaf.')
            ->setDefinitionHtml('A <em>modified</em> leaf.');

        $html = $item->getLinkHtml();

        $this->assertSame(
            '<a href="/glossary/bract" data-glossary="true"'
            . ' data-glossary-html="A &lt;em&gt;modified&lt;/em&gt; leaf."'
            . ' title="A modified leaf.">Bract</a>',
            $html
        );
    }

    public function testGetLinkHtmlWithCustomLabelAndNoHtmlDefinition(): void
    {
        $item = (new GlossaryItem('Bract', '/glossary/bract'))
            ->setDefinition('A "modified" leaf.');

        $html = $item->getLinkHtml('bracts');

        $this->assertSame(
            '<a href="/glossary/bract" data-glossary="true"'
            . ' title="A &quot;modified&quot; leaf.">bracts</a>',
            $html
        );
    }

    public function testGetLinkHtmlMarksItemsWithExtendedContent(): void
    {
        // data-glossary-more drives the tooltip's "Read more" link; only
        // items with a fuller glossary-page entry carry it
        $item = (new GlossaryItem('Bract', '/glossary/bract'))
            ->setDefinition('A modified leaf.')
            ->setExtendedContentHtml('<p>More detail.</p>');

        $this->assertStringContainsString('data-glossary-more="true"', $item->getLinkHtml());

        $plain = (new GlossaryItem('Bract', '/glossary/bract'))
            ->setDefinition('A modified leaf.');

        $this->assertStringNotContainsString('data-glossary-more', $plain->getLinkHtml());
    }

    public function testFluentSetters(): void
    {
        $item = new GlossaryItem('Bract', '/glossary/bract');

        $result = $item
            ->setDefinition('A modified leaf.')
            ->setDefinitionHtml('<p>A modified leaf.</p>')
            ->setExtendedContentHtml('<p>More detail.</p>')
            ->setPanelUrl('/panel/pages/glossary+bract')
            ->setSlug('bract');

        $this->assertSame($item, $result);
    }
}
