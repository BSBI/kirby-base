<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\GlossaryMatcherService;
use BSBI\WebBase\models\GlossaryMatch;
use PHPUnit\Framework\TestCase;

/**
 * Tests for GlossaryMatcherService.
 *
 * The matcher is a pure service: glossary terms + block HTML in, match objects
 * out. It must be case-insensitive, whole-word, skip text inside existing
 * links and HTML tags, and report only the first occurrence of each term.
 */
final class GlossaryMatcherServiceTest extends TestCase
{
    private GlossaryMatcherService $matcher;

    protected function setUp(): void
    {
        $this->matcher = new GlossaryMatcherService();
    }

    public function testEmptyTermListReturnsNoMatches(): void
    {
        $this->assertSame([], $this->matcher->findMatches([], '<p>Some content about plants</p>'));
    }

    public function testEmptyHtmlReturnsNoMatches(): void
    {
        $this->assertSame([], $this->matcher->findMatches(['bract'], ''));
    }

    public function testNoMatchesReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->matcher->findMatches(['bract'], '<p>Nothing relevant here</p>'));
    }

    public function testSingleMatchWithContext(): void
    {
        $html = '<p>The flower has a green bract beneath each petal.</p>';
        $matches = $this->matcher->findMatches(['bract'], $html, 'block-1');

        $this->assertCount(1, $matches);
        $match = $matches[0];
        $this->assertInstanceOf(GlossaryMatch::class, $match);
        $this->assertSame('bract', $match->getTerm());
        $this->assertSame('block-1', $match->getBlockId());
        $this->assertSame('The flower has a green', $match->getContextBefore());
        $this->assertSame('beneath each petal.', $match->getContextAfter());
    }

    public function testOffsetIsPositionInOriginalHtml(): void
    {
        $html = '<p>A green bract here.</p>';
        $matches = $this->matcher->findMatches(['bract'], $html);

        $this->assertCount(1, $matches);
        $this->assertSame(strpos($html, 'bract'), $matches[0]->getOffset());
    }

    public function testMatchingIsCaseInsensitive(): void
    {
        $html = '<p>Bracts vary. The Bract is often green. PLANT life abounds.</p>';
        $matches = $this->matcher->findMatches(['bract', 'plant'], $html);

        $this->assertCount(2, $matches);
        $terms = array_map(static fn (GlossaryMatch $m) => $m->getTerm(), $matches);
        $this->assertContains('bract', $terms);
        $this->assertContains('plant', $terms);
        // matched text preserves the original casing from the content
        $bractMatch = array_values(array_filter($matches, static fn (GlossaryMatch $m) => $m->getTerm() === 'bract'))[0];
        $this->assertSame('Bract', $bractMatch->getMatchedText());
    }

    public function testWholeWordMatchingOnly(): void
    {
        $html = '<p>The system of stems is complex.</p>';

        $this->assertSame([], $this->matcher->findMatches(['stem'], $html));
    }

    public function testFirstOccurrenceOnlyPerTerm(): void
    {
        $html = '<p>A bract is a bract is a bract.</p>';
        $matches = $this->matcher->findMatches(['bract'], $html);

        $this->assertCount(1, $matches);
        $this->assertSame(strpos($html, 'bract'), $matches[0]->getOffset());
    }

    public function testMultipleDifferentTermsAllMatched(): void
    {
        $html = '<p>The bract sits below the petiole of the leaf.</p>';
        $matches = $this->matcher->findMatches(['bract', 'petiole'], $html);

        $this->assertCount(2, $matches);
    }

    public function testNoMatchesInsideExistingLinks(): void
    {
        $html = '<p>See the <a href="/glossary/bract">bract</a> entry.</p>';

        $this->assertSame([], $this->matcher->findMatches(['bract'], $html));
    }

    public function testMatchOutsideLinkStillFoundWhenLinkContainsTerm(): void
    {
        $html = '<p>The <a href="/glossary/bract">bract</a> page describes what a bract is.</p>';
        $matches = $this->matcher->findMatches(['bract'], $html);

        $this->assertCount(1, $matches);
        $this->assertSame(strrpos($html, 'bract is'), $matches[0]->getOffset());
    }

    public function testNoMatchesInsideTagAttributes(): void
    {
        $html = '<p><img src="bract.jpg" alt="a bract"> Some other content.</p>';

        $this->assertSame([], $this->matcher->findMatches(['bract'], $html));
    }

    public function testEmptyAndWhitespaceTermsAreSkipped(): void
    {
        $html = '<p>A bract here.</p>';
        $matches = $this->matcher->findMatches(['', '  ', 'bract'], $html);

        $this->assertCount(1, $matches);
        $this->assertSame('bract', $matches[0]->getTerm());
    }

    public function testLongerTermPreferredOverContainedShorterTerm(): void
    {
        $html = '<p>The basal rosette forms first.</p>';
        $matches = $this->matcher->findMatches(['rosette', 'basal rosette'], $html);

        $this->assertCount(1, $matches);
        $this->assertSame('basal rosette', $matches[0]->getTerm());
    }

    public function testContextDoesNotContainPartialWords(): void
    {
        $prefix = 'Extraordinarily comprehensive descriptions of morphological characteristics precede the term';
        $html = '<p>' . $prefix . ' bract in botanical literature published throughout recent centuries.</p>';
        $matches = $this->matcher->findMatches(['bract'], $html);

        $this->assertCount(1, $matches);
        $before = $matches[0]->getContextBefore();
        $after = $matches[0]->getContextAfter();

        // context is limited in length and trimmed to whole words
        $this->assertLessThanOrEqual(45, strlen($before));
        $this->assertLessThanOrEqual(45, strlen($after));
        $this->assertStringEndsWith('precede the term', $before);
        $this->assertStringStartsWith('in botanical', $after);
    }

    public function testMultiWordTermMatches(): void
    {
        $html = '<p>The basal rosette overwinters.</p>';
        $matches = $this->matcher->findMatches(['basal rosette'], $html);

        $this->assertCount(1, $matches);
        $this->assertSame('basal rosette', $matches[0]->getTerm());
    }
}
