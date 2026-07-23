<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\GlossaryService;
use BSBI\WebBase\helpers\KirbyFieldReader;
use Kirby\Cms\App;
use Kirby\Cms\Block;
use PHPUnit\Framework\TestCase;

/**
 * Tests for GlossaryService.
 *
 * The service is the request-scoped entry point for glossary enrichment: it
 * resolves the glossary page from the `glossary.page` config option (a page
 * path — no full-index scans), builds the GlossaryList once per request, and
 * enriches block HTML via GlossaryLinkEnricher. With no option set the
 * feature is dormant and enrichment is a pass-through.
 *
 * Also documents Kirby behaviour the glossary design depends on: permalink
 * fragments (page://uuid#anchor) are stripped by permalinksToUrls(), which is
 * why glossary links must target glossary item pages directly.
 */
final class GlossaryServiceTest extends TestCase
{
    private static string $tmpDir;

    public static function setUpBeforeClass(): void
    {
        self::$tmpDir = sys_get_temp_dir() . '/kirby-glossary-service-test';
        if (!is_dir(self::$tmpDir)) {
            mkdir(self::$tmpDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        // Kirby registers error/exception handlers on App construction; restore
        // them so PHPUnit does not flag these tests as risky.
        restore_error_handler();
        restore_exception_handler();
    }

    private function makeApp(array $options = [], array $siteContent = []): App
    {
        return new App([
            'roots' => ['index' => self::$tmpDir],
            'options' => $options,
            'site' => [
                'content' => $siteContent,
                'children' => [
                    [
                        'slug' => 'discover',
                        'num' => 1,
                        'children' => [
                            [
                                'slug' => 'glossary',
                                'template' => 'glossary_listing',
                                'num' => 1,
                                'content' => ['uuid' => 'glossary-page-uuid'],
                                'children' => [
                                    [
                                        'slug' => 'bract',
                                        'template' => 'glossary_item',
                                        'num' => 1,
                                        'content' => [
                                            'title' => 'Bract',
                                            'uuid' => 'bract-uuid',
                                            'definition' => '<p>A modified leaf at the base of a <a href="page://petiole-uuid">flower</a>.</p>',
                                            'glossarytype' => 'botany',
                                            'extendedcontent' => '[{"content":{"text":"<p>Bracts occur in many families.</p>"},"id":"b1-block","isHidden":false,"type":"text"}]',
                                        ],
                                    ],
                                    [
                                        'slug' => 'petiole',
                                        'template' => 'glossary_item',
                                        'num' => 2,
                                        'content' => [
                                            'title' => 'Petiole',
                                            'uuid' => 'petiole-uuid',
                                            'definition' => '<p>The stalk &amp; support of a leaf.</p>',
                                        ],
                                    ],
                                    [
                                        'slug' => 'unlisted-term',
                                        'template' => 'glossary_item',
                                        'content' => [
                                            'title' => 'Unlisted Term',
                                            'definition' => 'Not yet published.',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    private const array GLOSSARY_OPTION = ['glossary' => ['page' => 'discover/glossary']];

    public function testDisabledWhenNeitherSiteFieldNorOptionSet(): void
    {
        $app = $this->makeApp();
        $service = new GlossaryService($app, $app->site());

        $html = '<p><a href="/discover/glossary/bract">bract</a></p>';

        $this->assertFalse($service->isEnabled());
        $this->assertSame($html, $service->enrichHtml($html));
    }

    public function testGlossaryResolvedFromSiteField(): void
    {
        // editors control the glossary location via the glossaryLocation site
        // field (a pages field storing the page UUID); no config needed
        $app = $this->makeApp([], ['glossarylocation' => '- page://glossary-page-uuid']);
        $service = new GlossaryService($app, $app->site());

        $this->assertTrue($service->isEnabled());
        $this->assertSame(2, $service->getGlossary()->count());
    }

    public function testSiteFieldTakesPrecedenceOverConfigOption(): void
    {
        $app = $this->makeApp(
            ['glossary' => ['page' => 'does/not/exist']],
            ['glossarylocation' => '- page://glossary-page-uuid']
        );
        $service = new GlossaryService($app, $app->site());

        $this->assertTrue($service->isEnabled());
        $this->assertSame(2, $service->getGlossary()->count());
    }

    public function testGlossaryBuiltFromListedChildren(): void
    {
        $app = $this->makeApp(self::GLOSSARY_OPTION);
        $service = new GlossaryService($app, $app->site());

        $this->assertTrue($service->isEnabled());

        $glossary = $service->getGlossary();
        $this->assertSame(2, $glossary->count());

        $bract = $glossary->findByTerm('Bract');
        $this->assertNotNull($bract);
        $this->assertSame('bract', $bract->getSlug());
        $this->assertSame('A modified leaf at the base of a flower.', $bract->getDefinition());
        $this->assertSame('botany', $bract->getType());

        // entities in writer definitions are decoded in the plain-text version
        $petiole = $glossary->findByTerm('Petiole');
        $this->assertNotNull($petiole);
        $this->assertSame('The stalk & support of a leaf.', $petiole->getDefinition());

        // optional extended content blocks are rendered to HTML; panel URLs let
        // editors jump from the listing to the item
        $this->assertStringContainsString('Bracts occur in many families.', $bract->getExtendedContentHtml());
        $this->assertFalse($petiole->hasExtendedContentHtml());
        $this->assertStringContainsString('/panel/pages/', $bract->getPanelUrl());
        $this->assertSame('page://bract-uuid', $bract->getUuid());

        $bractPage = $app->page('discover/glossary/bract');
        $this->assertNotNull($bractPage);
        $this->assertSame($bractPage->url(), $bract->getUrl());
    }

    public function testDefinitionHtmlResolvesPermalinksToOtherTerms(): void
    {
        // definitions are writer fields and may link to other glossary terms;
        // the HTML variant keeps those links (permalinks resolved to URLs)
        // while the plain-text variant used for hover titles strips them
        $app = $this->makeApp(self::GLOSSARY_OPTION);
        $service = new GlossaryService($app, $app->site());

        $bract = $service->getGlossary()->findByTerm('Bract');
        $this->assertNotNull($bract);

        $petiolePage = $app->page('discover/glossary/petiole');
        $this->assertNotNull($petiolePage);

        $this->assertStringContainsString('href="' . $petiolePage->url() . '"', $bract->getDefinitionHtml());
        $this->assertStringNotContainsString('page://', $bract->getDefinitionHtml());
        $this->assertStringNotContainsString('<a', $bract->getDefinition());
    }

    public function testBuildGlossaryFromPageWorksWithoutConfigOption(): void
    {
        // the listing page builds its glossary directly from the page being
        // rendered, so it works even before the glossary.page option is set
        $app = $this->makeApp();
        $service = new GlossaryService($app, $app->site());

        $glossaryPage = $app->page('discover/glossary');
        $this->assertNotNull($glossaryPage);

        $glossary = $service->buildGlossaryFromPage($glossaryPage);

        $this->assertSame(2, $glossary->count());
        $this->assertNotNull($glossary->findByTerm('Bract'));
    }

    public function testGetItemsForPickerReturnsUuidTitleAndTruncatedDefinition(): void
    {
        // feeds the writer toolbar "insert glossary link" dialog: one option
        // per term with the page uuid as the value
        $app = $this->makeApp(self::GLOSSARY_OPTION);
        $service = new GlossaryService($app, $app->site());

        $items = $service->getItemsForPicker();

        $this->assertCount(2, $items);
        $this->assertSame('Bract', $items[0]['title']);
        $this->assertSame('page://bract-uuid', $items[0]['uuid']);
        $this->assertSame('A modified leaf at the base of a flower.', $items[0]['definition']);
    }

    public function testGetItemsForPickerGeneratesUuidsAndTruncatesLongDefinitions(): void
    {
        // Kirby auto-generates a uuid when a page has none, so every item is
        // linkable; long definitions are shortened for the picker

        $app = new App([
            'roots' => ['index' => self::$tmpDir],
            'options' => ['glossary' => ['page' => 'glossary']],
            'site' => [
                'children' => [
                    [
                        'slug' => 'glossary',
                        'template' => 'glossary_listing',
                        'num' => 1,
                        'children' => [
                            [
                                'slug' => 'no-uuid-term',
                                'template' => 'glossary_item',
                                'num' => 1,
                                'content' => ['title' => 'No Uuid Term', 'definition' => '<p>Whatever.</p>'],
                            ],
                            [
                                'slug' => 'wordy',
                                'template' => 'glossary_item',
                                'num' => 2,
                                'content' => [
                                    'title' => 'Wordy',
                                    'uuid' => 'wordy-uuid',
                                    'definition' => '<p>' . str_repeat('word ', 40) . '</p>',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $service = new GlossaryService($app, $app->site());

        $items = $service->getItemsForPicker();

        $this->assertCount(2, $items);
        $this->assertStringStartsWith('page://', $items[0]['uuid']);
        $wordy = $items[1];
        $this->assertSame('Wordy', $wordy['title']);
        $this->assertLessThanOrEqual(83, strlen($wordy['definition']));
        $this->assertStringEndsWith('…', $wordy['definition']);
    }

    public function testUnlistedChildrenAreExcluded(): void
    {
        $app = $this->makeApp(self::GLOSSARY_OPTION);
        $service = new GlossaryService($app, $app->site());

        $this->assertNull($service->getGlossary()->findByTerm('Unlisted Term'));
    }

    public function testMissingGlossaryPageYieldsEmptyGlossary(): void
    {
        $app = $this->makeApp(['glossary' => ['page' => 'does/not/exist']]);
        $service = new GlossaryService($app, $app->site());

        $html = '<p><a href="/discover/glossary/bract">bract</a></p>';

        $this->assertSame(0, $service->getGlossary()->count());
        $this->assertSame($html, $service->enrichHtml($html));
    }

    public function testEnrichHtmlInjectsDefinitionTitle(): void
    {
        $app = $this->makeApp(self::GLOSSARY_OPTION);
        $service = new GlossaryService($app, $app->site());

        $bractPage = $app->page('discover/glossary/bract');
        $this->assertNotNull($bractPage);
        $html = '<p>See <a href="' . $bractPage->url() . '">bract</a>.</p>';

        $result = $service->enrichHtml($html);

        $this->assertStringContainsString('title="A modified leaf at the base of a flower."', $result);
    }

    public function testFieldReaderEnrichesGlossaryLinksInTextBlocks(): void
    {
        $app = $this->makeApp(self::GLOSSARY_OPTION);
        $reader = new KirbyFieldReader($app, $app->site(), new GlossaryService($app, $app->site()));

        $block = Block::factory([
            'type' => 'text',
            'content' => ['text' => '<p>See <a href="page://bract-uuid">bract</a>.</p>'],
        ]);

        $html = $reader->getHTMLfromBlock($block);
        $bractPage = $app->page('discover/glossary/bract');
        $this->assertNotNull($bractPage);

        $this->assertStringContainsString('href="' . $bractPage->url() . '"', $html);
        $this->assertStringContainsString('title="A modified leaf at the base of a flower."', $html);
    }

    public function testFieldReaderWithoutGlossaryServiceLeavesBlocksUntouched(): void
    {
        $app = $this->makeApp(self::GLOSSARY_OPTION);
        $reader = new KirbyFieldReader($app, $app->site());

        $block = Block::factory([
            'type' => 'text',
            'content' => ['text' => '<p>See <a href="page://bract-uuid">bract</a>.</p>'],
        ]);

        $html = $reader->getHTMLfromBlock($block);

        $this->assertStringNotContainsString('title=', $html);
    }

    public function testPermalinkFragmentsAreStrippedByKirby(): void
    {
        // Documents the Kirby behaviour the glossary design works around:
        // permalinksToUrls() resolves page://uuid#anchor to the page URL and
        // DROPS the fragment, so glossary links must target item pages, not
        // fragments within the listing page.
        $app = $this->makeApp(self::GLOSSARY_OPTION);
        $reader = new KirbyFieldReader($app, $app->site(), new GlossaryService($app, $app->site()));

        $block = Block::factory([
            'type' => 'text',
            'content' => ['text' => '<p><a href="page://bract-uuid#detail">bract</a></p>'],
        ]);

        $html = $reader->getHTMLfromBlock($block);
        $bractPage = $app->page('discover/glossary/bract');
        $this->assertNotNull($bractPage);

        $this->assertStringContainsString('href="' . $bractPage->url() . '"', $html);
        $this->assertStringNotContainsString('#detail', $html);
    }
}
