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

    public function testGlossaryLinkGetsTitleAndDataGlossaryAttributes(): void
    {
        // data-glossary is added at render time to every matched link (even
        // ones created with the plain Page link dialog) so front-end tooltip
        // and styling hooks apply uniformly
        $html = '<p>The <a href="https://example.test/glossary/bract">bract</a> is green.</p>';
        $expected = '<p>The <a href="https://example.test/glossary/bract" data-glossary="true"'
            . ' title="A modified leaf at the base of a flower.">bract</a> is green.</p>';

        $this->assertSame($expected, $this->enricher->enrich($html, $this->glossary));
    }

    public function testDefinitionHtmlIsCarriedInEscapedDataAttribute(): void
    {
        // when an item has an HTML definition (which may link to other
        // glossary terms), it is carried in data-glossary-html so front-end
        // tooltips can render the definition with its links intact
        $glossary = new GlossaryList();
        $glossary->addListItem(
            (new GlossaryItem('Bract', 'https://example.test/glossary/bract'))
                ->setDefinition('A modified leaf, see petiole.')
                ->setDefinitionHtml('A modified leaf, see <a href="https://example.test/glossary/petiole">petiole</a>.')
        );
        $html = '<p><a href="https://example.test/glossary/bract">bract</a></p>';

        $result = $this->enricher->enrich($html, $glossary);

        $this->assertStringContainsString(
            'data-glossary-html="A modified leaf, see '
            . '&lt;a href=&quot;https://example.test/glossary/petiole&quot;&gt;petiole&lt;/a&gt;."',
            $result
        );
        $this->assertStringContainsString('title="A modified leaf, see petiole."', $result);
    }

    public function testItemWithExtendedContentGetsDataGlossaryMoreAttribute(): void
    {
        // the tooltip shows a "Read more" link only for items with a fuller
        // entry on the glossary page
        $glossary = new GlossaryList();
        $glossary->addListItem(
            (new GlossaryItem('Bract', 'https://example.test/glossary/bract'))
                ->setDefinition('A modified leaf.')
                ->setExtendedContentHtml('<p>Bracts occur in many families.</p>')
        );
        $html = '<p><a href="https://example.test/glossary/bract">bract</a></p>';

        $this->assertStringContainsString(
            'data-glossary-more="true"',
            $this->enricher->enrich($html, $glossary)
        );
    }

    public function testItemWithoutExtendedContentHasNoDataGlossaryMoreAttribute(): void
    {
        $html = '<p><a href="https://example.test/glossary/bract">bract</a></p>';

        $this->assertStringNotContainsString(
            'data-glossary-more',
            $this->enricher->enrich($html, $this->glossary)
        );
    }

    public function testNoDefinitionHtmlMeansNoHtmlDataAttribute(): void
    {
        // fixture items in setUp have plain definitions only
        $html = '<p><a href="https://example.test/glossary/bract">bract</a></p>';

        $result = $this->enricher->enrich($html, $this->glossary);

        $this->assertStringNotContainsString('data-glossary-html', $result);
    }

    public function testDataGlossaryAttributeIsNotDuplicated(): void
    {
        $html = '<p><a href="https://example.test/glossary/bract" data-glossary="true">bract</a></p>';

        $result = $this->enricher->enrich($html, $this->glossary);

        $this->assertSame(1, substr_count($result, 'data-glossary'));
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
        $expected = '<p><a href="https://example.test/glossary/bract"'
            . ' title="A modified leaf at the base of a flower." data-glossary="true">bract</a></p>';

        $this->assertSame($expected, $this->enricher->enrich($html, $this->glossary));
    }

    public function testHrefWithFragmentStillMatchesItemUrl(): void
    {
        $html = '<p><a href="https://example.test/glossary/bract#detail">bract</a></p>';

        $result = $this->enricher->enrich($html, $this->glossary);

        $this->assertStringContainsString('title="A modified leaf at the base of a flower."', $result);
        $this->assertStringContainsString('href="https://example.test/glossary/bract#detail"', $result);
    }

    public function testGlossaryMarkLinksWithDataAttributeAreEnriched(): void
    {
        // links inserted by the writer toolbar glossary mark carry a
        // data-glossary attribute; enrichment must handle them and keep it
        $html = '<p><a href="https://example.test/glossary/bract" data-glossary="true">bract</a></p>';

        $result = $this->enricher->enrich($html, $this->glossary);

        $this->assertStringContainsString('title="A modified leaf at the base of a flower."', $result);
        $this->assertStringContainsString('data-glossary="true"', $result);
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
