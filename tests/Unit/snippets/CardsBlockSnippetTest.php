<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\snippets;

use BSBI\WebBase\Testing\KirbyContentBuilder;
use BSBI\WebBase\Testing\KirbyTestEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the `blocks/cards` snippet (bsbi-web#727, cropped images follow-up).
 *
 * A linked card used to wrap the whole card in an anchor. The description is
 * Kirbytext, so an editor's inline link produced an anchor inside an anchor;
 * browsers repair that by splitting the outer anchor, which broke the layout
 * (the image shrank to a thumbnail) and announced the card link three times.
 * The card is now a `<div>` whose title carries the link as a stretched link.
 *
 * Card images go through ImageService and the shared `base/image` snippet:
 * cropped to 4:3 by default (with the panel srcset), or a width-only thumbnail
 * when the block's `crop` toggle is off. The image cases use a real PNG so
 * thumb()/srcset() run for real; they are skipped when GD is unavailable.
 */
final class CardsBlockSnippetTest extends TestCase
{
    private const string IMAGE_ID = 'photos/photo.png';

    private static KirbyContentBuilder $content;

    private static bool $hasImage = false;

    public static function setUpBeforeClass(): void
    {
        $fixture = sys_get_temp_dir() . '/kirby-base-cards-fixture-' . uniqid();
        mkdir($fixture . '/photos', 0777, true);
        file_put_contents($fixture . '/site.txt', "Title: Test Site\n");
        file_put_contents($fixture . '/photos/photos.txt', "Title: Photos\n");
        file_put_contents(
            $fixture . '/photos/logo.svg',
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10"/></svg>'
        );

        // Editor-entered metadata with attribute-breaking characters: the alt (and the
        // title, which falls back to it) must reach the page escaped.
        file_put_contents($fixture . '/photos/photo.png.txt', 'Alt: Sedges & "rushes" <b>x</b>' . "\n");

        // A real 3:2 PNG: a 4:3 crop and a width-only resize to 400 wide then
        // differ in height (300 vs 267), so the two paths are distinguishable.
        if (function_exists('imagepng') && function_exists('imagewebp')) {
            $im = imagecreatetruecolor(900, 600);
            imagepng($im, $fixture . '/photos/photo.png');
            imagedestroy($im);
            self::$hasImage = true;
        }

        KirbyTestEnvironment::bootWithContent($fixture, 'kirby-base-cards-snippet', [
            'snippets' => [
                'base/image' => dirname(__DIR__, 3) . '/snippets/image.php',
            ],
            'options' => [
                'thumbs' => [
                    'srcsets' => [
                        'panel' => [
                            '400w' => ['width' => 400, 'height' => 300, 'crop' => true],
                            '800w' => ['width' => 800, 'height' => 600, 'crop' => true],
                        ],
                        'panel-webp' => [
                            '400w' => ['width' => 400, 'height' => 300, 'format' => 'webp', 'crop' => true],
                            '800w' => ['width' => 800, 'height' => 600, 'format' => 'webp', 'crop' => true],
                        ],
                        'default' => [
                            '300w' => ['width' => 300],
                            '600w' => ['width' => 600],
                        ],
                        'default-webp' => [
                            '300w' => ['width' => 300, 'format' => 'webp'],
                            '600w' => ['width' => 600, 'format' => 'webp'],
                        ],
                    ],
                ],
            ],
        ]);
        self::removeDir($fixture);
        self::$content = new KirbyContentBuilder();
    }

    private static function removeDir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? self::removeDir($path) : unlink($path);
        }
        rmdir($dir);
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
     * Renders one card carrying the fixture image, with any extra block fields.
     *
     * @param array<string, mixed> $blockFields Block-level fields (`crop`, `columns`).
     */
    private function renderImageCard(array $blockFields = []): string
    {
        if (!self::$hasImage) {
            $this->markTestSkipped('GD/WebP toolchain unavailable for real thumbnail generation');
        }

        return $this->render($blockFields + [
            'cards' => [[
                'title' => 'With image',
                'text'  => '',
                'url'   => 'https://example.test/with-image',
                'image' => [self::IMAGE_ID],
            ]],
        ]);
    }

    /**
     * The `<img>` tag's attributes, keyed by name.
     *
     * @return array<string, string>
     */
    private function imgAttributes(string $html): array
    {
        self::assertSame(1, preg_match('/<img\b([^>]*)>/s', $html, $m), "No <img> in:\n$html");
        preg_match_all('/([a-z-]+)="([^"]*)"/', $m[1], $pairs, PREG_SET_ORDER);
        $attributes = [];
        foreach ($pairs as [, $name, $value]) {
            $attributes[$name] = $value;
        }

        return $attributes;
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

    // region images

    public function testCardWithoutImageRendersNoFigure(): void
    {
        $html = $this->render([
            'cards' => [['title' => 'A', 'text' => '', 'url' => '', 'image' => []]],
        ]);

        self::assertStringNotContainsString('<figure', $html);
        self::assertStringNotContainsString('<img', $html);
    }

    public function testDanglingImageReferenceRendersNoFigure(): void
    {
        $html = $this->render([
            'cards' => [['title' => 'A', 'text' => '', 'url' => '', 'image' => ['file://no-such-file']]],
        ]);

        self::assertStringNotContainsString('<figure', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringContainsString('<h3 class="card-title">A</h3>', $html);
    }

    public function testCroppedCardServesA4x3ThumbnailWithThePanelSrcset(): void
    {
        $html = $this->renderImageCard(['crop' => 'true']);
        $img  = $this->imgAttributes($html);

        self::assertStringContainsString('<figure>', $html);
        self::assertStringContainsString('<picture>', $html);
        self::assertStringContainsString('<source type="image/webp"', $html);
        self::assertSame('card-img-top img-fix-size img-fix-size--four-three', $img['class']);
        self::assertSame('400', $img['width']);
        self::assertSame('300', $img['height']);
        self::assertMatchesRegularExpression('#/photo-400x300-crop[^"]*\.png$#', $img['src']);
        self::assertStringContainsString('photo-400x300-crop', $img['srcset']);
        self::assertStringContainsString(' 800w', $img['srcset']);
        self::assertStringNotContainsString('photos/photo.png"', $html, 'the original upload must not be served');
    }

    public function testCropIsOnWhenTheBlockPredatesTheToggle(): void
    {
        $img = $this->imgAttributes($this->renderImageCard());

        self::assertSame('card-img-top img-fix-size img-fix-size--four-three', $img['class']);
        self::assertSame('300', $img['height']);
        self::assertMatchesRegularExpression('#/photo-400x300-crop[^"]*\.png$#', $img['src']);
    }

    public function testUncroppedCardServesAWidthOnlyThumbnail(): void
    {
        $html = $this->renderImageCard(['crop' => 'false']);
        $img  = $this->imgAttributes($html);

        self::assertSame('card-img-top', $img['class']);
        self::assertStringNotContainsString('img-fix-size', $html);
        self::assertSame('400', $img['width']);
        self::assertArrayNotHasKey('height', $img, 'no fixed height when the image keeps its own ratio');
        self::assertMatchesRegularExpression('#/photo-400x(-[^"/]*)?\.png$#', $img['src'], 'a width-only thumbnail');
        self::assertStringNotContainsString('-crop', $img['src']);
        self::assertStringContainsString(' 600w', $img['srcset']);
        self::assertStringNotContainsString('-crop', $img['srcset']);
        self::assertStringNotContainsString('photos/photo.png"', $html, 'the original upload must not be served');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function sizesProvider(): array
    {
        return [
            'two'     => ['2 columns', '(min-width: 768px) 50vw, 100vw'],
            'three'   => ['3 columns', '(min-width: 992px) 33vw, (min-width: 768px) 50vw, 100vw'],
            'four'    => ['4', '(min-width: 992px) 25vw, (min-width: 768px) 33vw, 50vw'],
            'default' => ['', '(min-width: 992px) 33vw, (min-width: 768px) 50vw, 100vw'],
        ];
    }

    #[DataProvider('sizesProvider')]
    public function testSizesFollowTheColumnCount(string $columns, string $expectedSizes): void
    {
        $img = $this->imgAttributes($this->renderImageCard(['columns' => $columns]));

        self::assertSame($expectedSizes, $img['sizes']);
    }

    public function testSvgLogoIsServedAsItIs(): void
    {
        $html = $this->render([
            'cards' => [[
                'title' => 'Logo',
                'text'  => '',
                'url'   => '',
                'image' => ['photos/logo.svg'],
            ]],
        ]);
        $img = $this->imgAttributes($html);

        self::assertMatchesRegularExpression('#/logo\.svg$#', $img['src']);
        self::assertStringNotContainsString('logo-400x', $html, 'no thumbnail is made of a vector');
        self::assertSame('card-img-top img-fix-size img-fix-size--four-three', $img['class']);
        self::assertSame('object-fit: contain', $img['style'], 'the whole vector shows inside the 4:3 box');
        self::assertArrayNotHasKey('width', $img);
    }

    public function testUncroppedSvgHasNoBoxAndNoStyle(): void
    {
        $img = $this->imgAttributes($this->render([
            'crop'  => 'false',
            'cards' => [['title' => 'Logo', 'text' => '', 'url' => '', 'image' => ['photos/logo.svg']]],
        ]));

        self::assertSame('card-img-top', $img['class']);
        self::assertArrayNotHasKey('style', $img);
    }

    public function testImageAltAndTitleComeFromTheFileEscaped(): void
    {
        $html = $this->renderImageCard();
        $img  = $this->imgAttributes($html);

        self::assertSame('Sedges &amp; &quot;rushes&quot; &lt;b&gt;x&lt;/b&gt;', $img['alt']);
        self::assertSame(
            'Sedges &amp; &quot;rushes&quot; x',
            $img['title'],
            'the title is the caption (here the alt) without markup'
        );
        self::assertStringNotContainsString('"rushes"', $html);
        self::assertStringNotContainsString('<b>', $html);
    }

    public function testSvgWithoutAltStillCarriesAnEmptyAlt(): void
    {
        $img = $this->imgAttributes($this->render([
            'cards' => [['title' => 'Logo', 'text' => '', 'url' => '', 'image' => ['photos/logo.svg']]],
        ]));

        self::assertSame('', $img['alt']);
        self::assertArrayNotHasKey('title', $img);
    }

    // endregion
}
