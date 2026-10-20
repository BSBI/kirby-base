<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\FilteredFilesHelper;
use BSBI\WebBase\helpers\FilteredPagesHelper;
use BSBI\WebBase\helpers\StyleGuideService;
use Kirby\Cms\App;
use Kirby\Cms\Pages;
use PHPUnit\Framework\TestCase;

/**
 * The plugin's panel API routes take a page id from the request, so they must
 * honour the same page access the panel does (bsbi-web#830): a page the user
 * can't open is treated as not found, and pages the user can't list are left
 * out of results and options. Kirby's own routes do this through
 * isAccessible()/isListable(); these routes look pages up themselves.
 */
final class PanelRouteAccessTest extends TestCase
{
    private static App $kirby;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $root = sys_get_temp_dir() . '/kirby-base-route-access-' . uniqid();
        mkdir($root . '/content', 0777, true);
        mkdir($root . '/cache', 0777, true);

        $hidden = ['access' => ['restricted' => false], 'list' => ['restricted' => false]];

        self::$kirby = new App([
            'roots' => [
                'index'   => $root,
                'content' => $root . '/content',
                'cache'   => $root . '/cache',
            ],
            'blueprints' => [
                'users/restricted' => ['name' => 'restricted', 'title' => 'Restricted'],
                'pages/default'    => ['title' => 'Default'],
                'pages/secret'     => ['title' => 'Secret', 'options' => $hidden],
            ],
            'collections' => [
                'everything' => static fn (App $kirby): Pages => $kirby->page('open')?->children() ?? new Pages([]),
            ],
            'users' => [
                ['email' => 'restricted@test.invalid', 'role' => 'restricted'],
            ],
            'site' => [
                'children' => [
                    [
                        'slug'     => 'open',
                        'children' => [
                            ['slug' => 'public', 'content' => ['title' => 'Public', 'year' => '2025-01-01']],
                            ['slug' => 'response', 'template' => 'secret', 'content' => ['title' => 'Response', 'year' => '2024-01-01']],
                        ],
                        'files' => [['filename' => 'open.jpg', 'template' => 'image']],
                    ],
                    [
                        'slug'     => 'hidden',
                        'template' => 'secret',
                        'children' => [
                            ['slug' => 'child', 'content' => ['title' => 'Child of hidden', 'year' => '2023-01-01']],
                        ],
                        'files' => [['filename' => 'hidden.jpg', 'template' => 'image']],
                    ],
                ],
            ],
        ]);
    }

    protected function setUp(): void
    {
        self::$kirby->impersonate('restricted@test.invalid');
    }

    protected function tearDown(): void
    {
        self::$kirby->impersonate();
    }

    /**
     * @param array{items: array<int, array<string, mixed>>} $results
     * @return list<string>
     */
    private static function ids(array $results): array
    {
        return array_values(array_map(static fn (array $item): string => (string)$item['id'], $results['items']));
    }

    public function testPageResultsLeaveOutChildrenTheUserCannotList(): void
    {
        $results = FilteredPagesHelper::getResults('open', 'secret', [], [], [], '', 'title', 'asc', 1, 25);

        self::assertSame([], self::ids($results));
    }

    public function testPageResultsStillListChildrenTheUserCanList(): void
    {
        $results = FilteredPagesHelper::getResults('open', 'default', [], [], [], '', 'title', 'asc', 1, 25);

        self::assertSame(['open/public'], self::ids($results));
    }

    public function testPageResultsForAParentTheUserCannotOpenAreEmpty(): void
    {
        $results = FilteredPagesHelper::getResults('hidden', 'default', [], [], [], '', 'title', 'asc', 1, 25);

        self::assertSame([], self::ids($results));
    }

    public function testCollectionOptionsLeaveOutPagesTheUserCannotList(): void
    {
        $options = FilteredPagesHelper::getOptions(['related' => ['type' => 'pages', 'collection' => 'everything']]);

        self::assertSame(['open/public'], array_column($options['related'], 'value'));
    }

    public function testYearOptionsForAParentTheUserCannotOpenAreEmpty(): void
    {
        $options = FilteredPagesHelper::getOptions(['year' => ['type' => 'dateYear']], 'hidden', 'default');

        self::assertSame([], $options['year'] ?? []);
    }

    public function testFileResultsForAParentTheUserCannotOpenAreEmpty(): void
    {
        $results = FilteredFilesHelper::getResults('hidden', [], [], [], '', 'filename', 'asc', 1, 25);

        self::assertSame(0, $results['total']);
    }

    public function testFileResultsForAParentTheUserCanOpenStillList(): void
    {
        $results = FilteredFilesHelper::getResults('open', [], [], [], '', 'filename', 'asc', 1, 25);

        self::assertSame(1, $results['total']);
    }

    public function testFileCollectionOptionsLeaveOutPagesTheUserCannotList(): void
    {
        $options = FilteredFilesHelper::getOptions(['related' => ['type' => 'pages', 'collection' => 'everything']], 'open');

        self::assertSame(['open/public'], array_column($options['related'], 'value'));
    }

    public function testTheStyleGuideCheckTreatsAPageTheUserCannotOpenAsNotFound(): void
    {
        self::assertSame(['error' => 'Page not found: hidden'], StyleGuideService::check('hidden'));
    }
}
