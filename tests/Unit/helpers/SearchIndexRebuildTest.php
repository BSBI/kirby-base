<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\SearchIndexHelper;
use Kirby\Cms\App;
use Kirby\Cms\Pages;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The search rebuild's page sources (#52): the `allPages` collection, a logged fallback
 * to the site's pages without one, and the Panel-search table only when Panel search
 * is on — it walks every page on the site, drafts included.
 */
final class SearchIndexRebuildTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/kirby-base-search-rebuild-' . uniqid();
        foreach (['1_alpha' => 'Alpha', 'beta' => 'Beta', '_drafts/gamma' => 'Gamma'] as $dir => $title) {
            mkdir($this->root . '/content/' . $dir, 0777, true);
            file_put_contents($this->root . '/content/' . $dir . '/default.txt', "Title: $title\n");
        }
        mkdir($this->root . '/site', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testWithoutAllPagesItIndexesTheSitesPagesAndLogsIt(): void
    {
        $this->boot();

        $result = (new SearchIndexHelper())->rebuildIndex();

        // listed and unlisted; never drafts
        $this->assertSame(2, $result['search_index']);
        $this->assertTrue($result['used_fallback']);
        $this->assertStringContainsString('allPages', (string)file_get_contents($this->root . '/site/logs/search-index.log'));
    }

    public function testWithAllPagesItIndexesThatCollection(): void
    {
        $this->boot(collections: ['allPages' => fn () => site()->children()->listed()]);

        $result = (new SearchIndexHelper())->rebuildIndex();

        $this->assertSame(1, $result['search_index']);
        $this->assertFalse($result['used_fallback']);
    }

    public function testWithPanelSearchOffThePanelSearchTableIsNotRebuilt(): void
    {
        $this->boot(collections: ['allPages' => fn () => site()->children()->listed()]);

        $result = (new SearchIndexHelper())->rebuildIndex();

        $this->assertNull($result['all_pages']);
        $this->assertSame(0, $this->allPagesRows());
    }

    public function testWithPanelSearchOnThePanelSearchTableHoldsEveryPage(): void
    {
        $this->boot(['search.panelSearch' => true], ['allPages' => fn () => site()->children()->listed()]);

        $result = (new SearchIndexHelper())->rebuildIndex();

        $this->assertSame(3, $result['all_pages']);
        $this->assertSame(3, $this->allPagesRows());
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, callable(): Pages> $collections
     */
    private function boot(array $options = [], array $collections = []): void
    {
        new App([
            'roots' => [
                'index'   => $this->root,
                'content' => $this->root . '/content',
                'site'    => $this->root . '/site',
                'logs'    => $this->root . '/site/logs',
                'cache'   => $this->root . '/cache',
            ],
            'options' => ['search.databasePath' => '/search/search.sqlite'] + $options,
            'collections' => $collections,
        ]);
        // booting registers Kirby's handlers, which PHPUnit flags as risky
        restore_error_handler();
        restore_exception_handler();
        App::instance()->impersonate('kirby');
    }

    private function allPagesRows(): int
    {
        $database = new PDO('sqlite:' . $this->root . '/site/search/search.sqlite');
        $table = $database->query("SELECT name FROM sqlite_master WHERE type='table' AND name='all_pages'")->fetch();

        return $table === false ? 0 : (int)$database->query('SELECT COUNT(*) FROM all_pages')->fetchColumn();
    }
}
