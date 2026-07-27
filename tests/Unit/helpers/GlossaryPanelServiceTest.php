<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\GlossaryPanelService;
use BSBI\WebBase\helpers\GlossaryService;
use Kirby\Cms\App;
use PHPUnit\Framework\TestCase;

/**
 * Tests for GlossaryPanelService.
 *
 * The service powers the panel "Add glossary links" UI: preview walks a
 * page's content fields (layout or blocks JSON) collecting glossary matches
 * with context for editor review; apply materialises the confirmed links into
 * the block JSON as page:// permalinks.
 */
final class GlossaryPanelServiceTest extends TestCase
{
    private const string LAYOUT_JSON = '[{"attrs":[],"columns":[{"blocks":['
        . '{"content":{"text":"<p>All about the bract in flowers.</p>"},"id":"blk-bract","isHidden":false,"type":"text"},'
        . '{"content":{"text":"<p>See <a href=\"page://petiole-uuid\">petiole</a> too.</p>"},"id":"blk-linked","isHidden":false,"type":"text"}'
        . '],"id":"col-1","width":"1/1"}],"id":"layout-1"}]';

    private const array GLOSSARY_OPTION = ['glossary' => ['page' => 'glossary']];

    private static string $tmpDir;

    public static function setUpBeforeClass(): void
    {
        self::$tmpDir = sys_get_temp_dir() . '/kirby-glossary-panel-test';
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

    private function makeApp(): App
    {
        return new App([
            'roots' => ['index' => self::$tmpDir],
            'options' => self::GLOSSARY_OPTION,
            'site' => [
                'children' => [
                    [
                        'slug' => 'glossary',
                        'template' => 'glossary_listing',
                        'num' => 1,
                        'children' => [
                            [
                                'slug' => 'bract',
                                'template' => 'glossary_item',
                                'num' => 1,
                                'content' => [
                                    'title' => 'Bract',
                                    'uuid' => 'bract-uuid',
                                    'definition' => '<p>A modified leaf.</p>',
                                ],
                            ],
                            [
                                'slug' => 'petiole',
                                'template' => 'glossary_item',
                                'num' => 2,
                                'content' => [
                                    'title' => 'Petiole',
                                    'uuid' => 'petiole-uuid',
                                    'definition' => '<p>The stalk of a leaf.</p>',
                                ],
                            ],
                        ],
                    ],
                    [
                        'slug' => 'about-bracts',
                        'template' => 'content',
                        'num' => 2,
                        'content' => [
                            'title' => 'About Bracts',
                            'maincontent' => self::LAYOUT_JSON,
                        ],
                    ],
                ],
            ],
        ]);
    }

    private function makeService(App $app): GlossaryPanelService
    {
        return new GlossaryPanelService($app, $app->site(), new GlossaryService($app, $app->site()));
    }

    public function testPreviewFindsUnlinkedTermsWithContext(): void
    {
        $app = $this->makeApp();
        $page = $app->page('about-bracts');
        $this->assertNotNull($page);

        $matches = $this->makeService($app)->previewForPage($page);

        $this->assertCount(1, $matches);
        $this->assertSame('Bract', $matches[0]['term']);
        $this->assertSame('blk-bract', $matches[0]['blockId']);
        $this->assertSame('bract', $matches[0]['matchedText']);
        $this->assertSame('All about the', $matches[0]['contextBefore']);
        $this->assertSame('in flowers.', $matches[0]['contextAfter']);
    }

    public function testPreviewSkipsTermsAlreadyLinked(): void
    {
        $app = $this->makeApp();
        $page = $app->page('about-bracts');
        $this->assertNotNull($page);

        $matches = $this->makeService($app)->previewForPage($page);

        $terms = array_column($matches, 'term');
        $this->assertNotContains('Petiole', $terms);
    }

    public function testApplySelectionsRewritesBlockJson(): void
    {
        $app = $this->makeApp();
        $service = $this->makeService($app);

        $result = $service->applySelectionsToFieldJson(
            self::LAYOUT_JSON,
            [['blockId' => 'blk-bract', 'term' => 'Bract']],
            (new GlossaryService($app, $app->site()))->getGlossary()
        );

        $this->assertSame(1, $result['applied']);
        $this->assertStringContainsString('<a href=\"/@/page/bract-uuid\" data-glossary=\"true\">bract</a>', $result['json']);
        // the untouched block is preserved
        $this->assertStringContainsString('blk-linked', $result['json']);
    }

    public function testApplyIgnoresUnknownTermsAndBlocks(): void
    {
        $app = $this->makeApp();
        $service = $this->makeService($app);
        $glossary = (new GlossaryService($app, $app->site()))->getGlossary();

        $result = $service->applySelectionsToFieldJson(
            self::LAYOUT_JSON,
            [
                ['blockId' => 'blk-bract', 'term' => 'Unknown'],
                ['blockId' => 'no-such-block', 'term' => 'Bract'],
            ],
            $glossary
        );

        $this->assertSame(0, $result['applied']);
        $this->assertSame(self::LAYOUT_JSON, $result['json']);
    }

    public function testApplyHandlesPlainBlocksJson(): void
    {
        $app = $this->makeApp();
        $service = $this->makeService($app);
        $blocksJson = '[{"content":{"text":"<p>A bract here.</p>"},"id":"blk-plain","isHidden":false,"type":"text"}]';

        $result = $service->applySelectionsToFieldJson(
            $blocksJson,
            [['blockId' => 'blk-plain', 'term' => 'Bract']],
            (new GlossaryService($app, $app->site()))->getGlossary()
        );

        $this->assertSame(1, $result['applied']);
        $this->assertStringContainsString('/@/page/bract-uuid', $result['json']);
    }

    public function testPreviewForPageCanBeRestrictedToGivenTerms(): void
    {
        $app = $this->makeApp();
        $page = $app->page('about-bracts');
        $this->assertNotNull($page);
        $service = $this->makeService($app);

        // 'Bract' matches in the fixture; restricting to 'Petiole' must not
        // surface it
        $this->assertSame([], $service->previewForPage($page, ['Petiole']));
        $this->assertCount(1, $service->previewForPage($page, ['Bract']));
    }

    public function testApplyTermToPageAppliesOnlyThatTerm(): void
    {
        $app = $this->makeApp();
        $app->impersonate('kirby');
        $page = $app->page('about-bracts');
        $this->assertNotNull($page);
        $service = $this->makeService($app);

        $matches = $service->applyTermToPage($page, 'Bract');

        $this->assertCount(1, $matches);
        $this->assertSame('bract', $matches[0]['matchedText']);
        $this->assertSame('All about the', $matches[0]['contextBefore']);
    }

    public function testApplyTermToPageWithUnknownOrAlreadyLinkedTermDoesNothing(): void
    {
        $app = $this->makeApp();
        $app->impersonate('kirby');
        $page = $app->page('about-bracts');
        $this->assertNotNull($page);
        $service = $this->makeService($app);

        $this->assertSame([], $service->applyTermToPage($page, 'Unknown'));
        // petiole is already linked in the fixture block
        $this->assertSame([], $service->applyTermToPage($page, 'Petiole'));
    }

    public function testCandidatePageIdsExcludeGlossarySubtree(): void
    {
        $app = $this->makeApp();
        $service = $this->makeService($app);

        $ids = $service->getCandidatePageIds();

        $this->assertContains('about-bracts', $ids);
        $this->assertNotContains('glossary', $ids);
        $this->assertNotContains('glossary/bract', $ids);
        $this->assertNotContains('glossary/petiole', $ids);
    }

    public function testAppendToItemLogAccumulatesEntries(): void
    {
        $app = $this->makeApp();
        $app->impersonate('kirby');
        $itemPage = $app->page('glossary/bract');
        $this->assertNotNull($itemPage);
        $service = $this->makeService($app);

        $updated = $service->appendToItemLog($itemPage, [
            ['date' => '2026-07-27 10:00', 'page' => 'about-bracts', 'title' => 'About Bracts', 'applied' => 1],
        ]);
        $updated = $service->appendToItemLog($updated, [
            ['date' => '2026-07-27 10:01', 'page' => 'other', 'title' => 'Other', 'applied' => 2],
        ]);

        $log = json_decode($updated->content()->get('addtopageslog')->value(), true);
        $this->assertCount(2, $log);
        $this->assertSame('about-bracts', $log[0]['page']);
        $this->assertSame('Other', $log[1]['title']);
    }

    public function testItemLogIsCappedAtMostRecentEntries(): void
    {
        // repeated site-wide runs must not balloon the stored log field
        $app = $this->makeApp();
        $app->impersonate('kirby');
        $itemPage = $app->page('glossary/bract');
        $this->assertNotNull($itemPage);
        $service = $this->makeService($app);

        $entries = [];
        for ($i = 0; $i < 505; $i++) {
            $entries[] = ['date' => '2026-07-27', 'page' => 'page-' . $i, 'applied' => 1];
        }
        $updated = $service->appendToItemLog($itemPage, $entries);

        $log = json_decode($updated->content()->get('addtopageslog')->value(), true);
        $this->assertCount(500, $log);
        // the oldest entries are dropped, the newest kept
        $this->assertSame('page-5', $log[0]['page']);
        $this->assertSame('page-504', $log[499]['page']);
    }

    public function testApplyToPagePersistsLinks(): void
    {
        $app = $this->makeApp();
        $app->impersonate('kirby');
        $page = $app->page('about-bracts');
        $this->assertNotNull($page);

        $applied = $this->makeService($app)->applyToPage($page, [['blockId' => 'blk-bract', 'term' => 'Bract']]);

        $this->assertSame(1, $applied);
    }
}
