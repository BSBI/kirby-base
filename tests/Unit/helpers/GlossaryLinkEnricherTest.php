<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\GlossaryLinkEnricher;
use BSBI\WebBase\models\GlossaryItem;
use BSBI\WebBase\models\GlossaryList;
use PHPUnit\Framework\TestCase;

/**
 * Tests for GlossaryLinkEnricher.
 *
 * The enricher post-processes block HTML after permalinks have been resolved
 * to URLs: any link whose href matches a glossary item's URL gets a title
 * attribute carrying the item's definition, so the definition appears on
 * hover while remaining single-sourced in the glossary.
 */
final class GlossaryLinkEnricherTest extends TestCase
{
    private GlossaryLinkEnricher $enricher;
    private GlossaryList $glossary;

    protected function setUp(): void
    {
        $this->enricher = new GlossaryLinkEnricher();
        $this->glossary = new GlossaryList();
        $this->glossary->addListItem(
            (new GlossaryItem('Bract', 'https://example.test/glossary/bract'))
                ->setDefinition('A modified leaf at the base of a flower.')
                ->setSlug('bract')
        );
        $this->glossary->addListItem(
            (new GlossaryItem('Petiole', 'https://example.test/glossary/petiole'))
                ->setDefinition('The stalk of a leaf.')
                ->setSlug('petiole')
        );
    }

    public function testHtmlWithoutLinksIsUnchanged(): void
    {
        $html = '<p>No links here.</p>';

        $this->assertSame($html, $this->enricher->enrich($html, $this->glossary));
    }

    public function testGlossaryLinkGetsTitleAttribute(): void
    {
        $html = '<p>The <a href="https://example.test/glossary/bract">bract</a> is green.</p>';
        $expected = '<p>The <a href="https://example.test/glossary/bract" title="A modified leaf at the base of a flower.">bract</a> is green.</p>';

        $this->assertSame($expected, $this->enricher->enrich($html, $this->glossary));
    }

    public function testMultipleGlossaryLinksAllEnriched(): void
    {
        $html = '<p><a href="https://example.test/glossary/bract">bract</a> and '
            . '<a href="https://example.test/glossary/petiole">petiole</a></p>';

        $result = $this->enricher->enrich($html, $this->glossary);

        $this->assertStringContainsString('title="A modified leaf at the base of a flower."', $result);
        $this->assertStringContainsString('title="The stalk of a leaf."', $result);
    }

    public function testNonGlossaryLinksAreUnchanged(): void
    {
        $html = '<p><a href="https://example.test/news/latest">news</a> and '
            . '<a href="https://elsewhere.example/bract">external</a></p>';

        $this->assertSame($html, $this->enricher->enrich($html, $this->glossary));
    }

    public function testExistingTitleAttributeIsReplaced(): void
    {
        $html = '<p><a href="https://example.test/glossary/bract" title="old title">bract</a></p>';
        $expected = '<p><a href="https://example.test/glossary/bract" title="A modified leaf at the base of a flower.">bract</a></p>';

        $this->assertSame($expected, $this->enricher->enrich($html, $this->glossary));
    }

    public function testHrefWithFragmentStillMatchesItemUrl(): void
    {
        $html = '<p><a href="https://example.test/glossary/bract#detail">bract</a></p>';

        $result = $this->enricher->enrich($html, $this->glossary);

        $this->assertStringContainsString('title="A modified leaf at the base of a flower."', $result);
        $this->assertStringContainsString('href="https://example.test/glossary/bract#detail"', $result);
    }

    public function testDefinitionIsHtmlEscapedInAttribute(): void
    {
        $glossary = new GlossaryList();
        $glossary->addListItem(
            (new GlossaryItem('Awn', 'https://example.test/glossary/awn'))
                ->setDefinition('A "bristle" on grasses & sedges.')
        );
        $html = '<p><a href="https://example.test/glossary/awn">awn</a></p>';

        $result = $this->enricher->enrich($html, $glossary);

        $this->assertStringContainsString('title="A &quot;bristle&quot; on grasses &amp; sedges."', $result);
    }

    public function testItemWithoutDefinitionIsNotEnriched(): void
    {
        $glossary = new GlossaryList();
        $glossary->addListItem(new GlossaryItem('Bract', 'https://example.test/glossary/bract'));
        $html = '<p><a href="https://example.test/glossary/bract">bract</a></p>';

        $this->assertSame($html, $this->enricher->enrich($html, $glossary));
    }

    public function testEmptyGlossaryLeavesHtmlUnchanged(): void
    {
        $html = '<p><a href="https://example.test/glossary/bract">bract</a></p>';

        $this->assertSame($html, $this->enricher->enrich($html, new GlossaryList()));
    }

    public function testEmptyHtmlReturnsEmptyString(): void
    {
        $this->assertSame('', $this->enricher->enrich('', $this->glossary));
    }
}
