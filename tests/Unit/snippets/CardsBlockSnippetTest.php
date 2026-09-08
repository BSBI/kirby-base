<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\snippets;

use BSBI\WebBase\Testing\KirbyContentBuilder;
use BSBI\WebBase\Testing\KirbyTestEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the `blocks/cards` snippet (bsbi-web#727).
 *
 * A linked card used to wrap the whole card in an anchor. The description is
 * Kirbytext, so an editor's inline link produced an anchor inside an anchor;
 * browsers repair that by splitting the outer anchor, which broke the layout
 * (the image shrank to a thumbnail) and announced the card link three times.
 * The card is now a `<div>` whose title carries the link as a stretched link.
 */
final class CardsBlockSnippetTest extends TestCase
{
    private static KirbyContentBuilder $content;

    public static function setUpBeforeClass(): void
    {
        KirbyTestEnvironment::boot('kirby-base-cards-snippet-' . uniqid());
        self::$content = new KirbyContentBuilder();
    }

    /**
     * Renders the snippet file with `$block` in scope, as Kirby's snippet()
     * helper would.
     *
     * @param array<string, mixed> $blockContent The cards block's content.
     */
    private function render(array $blockContent): string
    {
        $block = self::$content->block([
            'type'    => 'cards',
            'content' => $blockContent,
        ]);

        $renderer = static function (string $snippetFile, array $snippetData): string {
            extract($snippetData);
            ob_start();
            include $snippetFile;

            return (string)ob_get_clean();
        };

        return $renderer(dirname(__DIR__, 3) . '/snippets/blocks/cards.php', ['block' => $block]);
    }

    /**
     * True if any `<a` opens while a previous `<a` is still open — the invalid
     * nesting the browser would repair by splitting the outer anchor.
     */
    private function hasNestedAnchor(string $html): bool
    {
        preg_match_all('/<a\b|<\/a>/i', $html, $matches, PREG_OFFSET_CAPTURE);
        $depth = 0;
        foreach ($matches[0] as [$tag]) {
            if (strtolower($tag) === '</a>') {
                $depth = max(0, $depth - 1);
                continue;
            }
            if ($depth > 0) {
                return true;
            }
            $depth++;
        }

        return false;
    }

    public function testLinkedCardWithLinkInTextRendersNoNestedAnchor(): void
    {
        $html = $this->render([
            'cards' => [[
                'title' => '100 Plants Challenge',
                'text'  => 'If you think you might be a (link: https://example.test/skills text: botanical skill level) of 3 or above, could you lead a walk?',
                'url'   => 'https://example.test/100-plants',
            ]],
        ]);

        self::assertFalse($this->hasNestedAnchor($html), "Nested anchor found in:\n$html");
        self::assertSame(1, substr_count($html, 'stretched-link'));
        self::assertMatchesRegularExpression(
            '/<h3 class="card-title"><a href="https:\/\/example\.test\/100-plants" class="[^"]*stretched-link[^"]*">100 Plants Challenge<\/a><\/h3>/',
            $html
        );
        self::assertStringContainsString('<a href="https://example.test/skills">botanical skill level</a>', $html);
        self::assertStringNotContainsString('<a href="https://example.test/100-plants" class="card', $html);
    }

    public function testLinkedCardWithPlainTextLinksTheTitle(): void
    {
        $html = $this->render([
            'cards' => [[
                'title' => 'New Year Plant Hunt',
                'text'  => 'Could you organise a Group Hunt?',
                'url'   => 'https://example.test/nyph',
            ]],
        ]);

        self::assertStringContainsString('<div class="card border-0 flex-fill">', $html);
        self::assertStringNotContainsString('<a href="https://example.test/nyph" class="card', $html);
        self::assertSame(1, substr_count($html, '<a '));
        self::assertStringContainsString('class="stretched-link', $html);
        self::assertStringContainsString('<p>Could you organise a Group Hunt?</p>', $html);
    }

    public function testUnlinkedCardRendersNoAnchor(): void
    {
        $html = $this->render([
            'cards' => [[
                'title' => 'Just a card',
                'text'  => 'No link here.',
                'url'   => '',
            ]],
        ]);

        self::assertStringNotContainsString('<a ', $html);
        self::assertStringContainsString('<h3 class="card-title">Just a card</h3>', $html);
    }

    public function testLinkedCardWithoutTitleStillRendersTheLink(): void
    {
        $html = $this->render([
            'cards' => [[
                'title' => '',
                'text'  => 'Text only.',
                'url'   => 'https://example.test/untitled',
            ]],
        ]);

        self::assertFalse($this->hasNestedAnchor($html));
        self::assertMatchesRegularExpression(
            '/<a href="https:\/\/example\.test\/untitled" class="[^"]*stretched-link[^"]*">https:\/\/example\.test\/untitled<\/a>/',
            $html
        );
    }

    public function testUrlIsEscapedInHref(): void
    {
        $html = $this->render([
            'cards' => [[
                'title' => 'Escaped',
                'text'  => '',
                'url'   => 'https://example.test/?a=1&b="2"',
            ]],
        ]);

        self::assertStringContainsString('href="https://example.test/?a=1&amp;b=&quot;2&quot;"', $html);
        self::assertStringNotContainsString('b="2"', $html);
    }

    public function testTitleAndUrlFallbackAreEscapedAsText(): void
    {
        $html = $this->render([
            'cards' => [
                ['title' => 'Sedges & <rushes>', 'text' => '', 'url' => ''],
                ['title' => '', 'text' => '', 'url' => 'https://example.test/?a=1&b=<2>'],
            ],
        ]);

        self::assertStringContainsString('<h3 class="card-title">Sedges &amp; &lt;rushes&gt;</h3>', $html);
        self::assertStringContainsString('>https://example.test/?a=1&amp;b=&lt;2&gt;</a>', $html);
        self::assertStringNotContainsString('<rushes>', $html);
        self::assertStringNotContainsString('<2>', $html);
    }

    public function testEmptyCardsRenderNothing(): void
    {
        self::assertSame('', $this->render(['title' => 'Nothing to see', 'cards' => []]));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function columnClassProvider(): array
    {
        return [
            'two'     => ['2 columns', 'col-12 col-md-6'],
            'three'   => ['3 columns', 'col-12 col-sm-6 col-lg-4'],
            'four'    => ['4 columns', 'col-12 col-sm-6 col-lg-3'],
            'numeric' => ['4', 'col-12 col-sm-6 col-lg-3'],
            'default' => ['', 'col-12 col-sm-6 col-lg-4'],
        ];
    }

    #[DataProvider('columnClassProvider')]
    public function testColumnsSelectTheGridClass(string $columns, string $expectedClass): void
    {
        $html = $this->render([
            'columns' => $columns,
            'cards'   => [['title' => 'A', 'text' => '', 'url' => '']],
        ]);

        self::assertStringContainsString('<div class="' . $expectedClass . ' mb-4 d-flex">', $html);
    }

    public function testSectionTitleRendersAsHeading(): void
    {
        $html = $this->render([
            'title' => 'You could also try',
            'cards' => [['title' => 'A', 'text' => '', 'url' => '']],
        ]);

        self::assertStringContainsString('<h2 class="text-center mb-4">You could also try</h2>', $html);
    }

    public function testSectionTitleIsEscaped(): void
    {
        $html = $this->render([
            'title' => 'Talks & <walks>',
            'cards' => [['title' => 'A', 'text' => '', 'url' => '']],
        ]);

        self::assertStringContainsString('<h2 class="text-center mb-4">Talks &amp; &lt;walks&gt;</h2>', $html);
    }
}
