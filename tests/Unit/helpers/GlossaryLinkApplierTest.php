<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\GlossaryLinkApplier;
use PHPUnit\Framework\TestCase;

/**
 * Tests for GlossaryLinkApplier.
 *
 * The applier materialises glossary links into block HTML: for each supplied
 * term it wraps the first available whole-word occurrence in an anchor whose
 * href is the glossary item's page:// permalink (so the link survives page
 * moves and is resolved to a URL at render time).
 */
final class GlossaryLinkApplierTest extends TestCase
{
    private GlossaryLinkApplier $applier;

    protected function setUp(): void
    {
        $this->applier = new GlossaryLinkApplier();
    }

    public function testWrapsFirstOccurrenceWithPermalink(): void
    {
        $html = '<p>The bract is green.</p>';

        $result = $this->applier->applyLinks($html, ['bract' => 'abc123']);

        $this->assertSame('<p>The <a href="/@/page/abc123" data-glossary="true">bract</a> is green.</p>', $result);
    }

    public function testAcceptsFullPermalinkUuid(): void
    {
        $html = '<p>The bract is green.</p>';

        $result = $this->applier->applyLinks($html, ['bract' => 'page://abc123']);

        $this->assertSame('<p>The <a href="/@/page/abc123" data-glossary="true">bract</a> is green.</p>', $result);
    }

    public function testPreservesOriginalCasingOfMatchedText(): void
    {
        $html = '<p>Bracts vary; the Bract is green.</p>';

        $result = $this->applier->applyLinks($html, ['bract' => 'abc123']);

        $this->assertSame('<p>Bracts vary; the <a href="/@/page/abc123" data-glossary="true">Bract</a> is green.</p>', $result);
    }

    public function testMultipleTermsAppliedInOnePass(): void
    {
        $html = '<p>The bract sits below the petiole.</p>';

        $result = $this->applier->applyLinks($html, [
            'bract' => 'abc123',
            'petiole' => 'def456',
        ]);

        $this->assertSame(
            '<p>The <a href="/@/page/abc123" data-glossary="true">bract</a> sits below the <a href="/@/page/def456" data-glossary="true">petiole</a>.</p>',
            $result
        );
    }

    public function testAlreadyLinkedOccurrenceIsSkipped(): void
    {
        $html = '<p>See <a href="/glossary/bract">bract</a> for details.</p>';

        $result = $this->applier->applyLinks($html, ['bract' => 'abc123']);

        $this->assertSame($html, $result);
    }

    public function testHrefIsHtmlEscaped(): void
    {
        // UUIDs from Kirby never contain quotes, but the applier must not
        // trust that: a quote in the uuid cannot break out of the attribute
        $html = '<p>The bract is green.</p>';

        $result = $this->applier->applyLinks($html, ['bract' => 'abc"123']);

        $this->assertStringContainsString('href="/@/page/abc&quot;123"', $result);
        $this->assertStringNotContainsString('href="/@/page/abc"123"', $result);
    }

    public function testMultiByteContentBeforeMatchKeepsOffsetsCorrect(): void
    {
        $html = '<p>Saxifraga aizoöides — élégant petites feuilles: the bract is green.</p>';

        $result = $this->applier->applyLinks($html, ['bract' => 'abc123']);

        $this->assertStringContainsString('the <a href="/@/page/abc123" data-glossary="true">bract</a> is green', $result);
    }

    public function testApplierOutputSurvivesKirbySanitisation(): void
    {
        // regression: Kirby's Sane\Html strips page:// hrefs on panel save,
        // so applied links must use the /@/page/ permalink form it allows
        $result = $this->applier->applyLinks('<p>The bract is green.</p>', ['bract' => 'page://abc123']);

        $this->assertStringContainsString('href=', $result);
        $this->assertSame($result, \Kirby\Sane\Html::sanitize($result));
    }

    public function testTermNotPresentLeavesHtmlUnchanged(): void
    {
        $html = '<p>Nothing relevant here.</p>';

        $this->assertSame($html, $this->applier->applyLinks($html, ['bract' => 'abc123']));
    }

    public function testEmptyTermMapLeavesHtmlUnchanged(): void
    {
        $html = '<p>The bract is green.</p>';

        $this->assertSame($html, $this->applier->applyLinks($html, []));
    }
}
