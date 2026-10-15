<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\ContentIndexDefinition;
use BSBI\WebBase\helpers\ContentIndexManager;
use BSBI\WebBase\helpers\KirbyBaseHelper;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * ContentIndexManager's writes against a real Kirby site and SQLite file (#52):
 * a rebuild that never holds the write lock while it reads pages, keeps rows a
 * walk could not see, and an indexPage() that never loses a row it cannot replace.
 */
final class ContentIndexRebuildTest extends TestCase
{
    private static string $root;

    /** @var callable(Page): void|null called from getRowData, to act mid-rebuild */
    private static $onRow = null;

    /** @var list<string> slugs whose getRowData throws */
    private static array $failing = [];

    /** @var list<string> slugs whose definition gives no row: the page is no longer indexed */
    private static array $rowless = [];

    /** @var list<string> slugs the collection leaves out, as if created after the walk passed */
    private static array $unwalked = [];

    public static function setUpBeforeClass(): void
    {
        self::$root = sys_get_temp_dir() . '/kirby-base-content-rebuild-' . uniqid();
        mkdir(self::$root . '/content', 0777, true);
        mkdir(self::$root . '/site', 0777, true);

        new App([
            'roots' => [
                'index'   => self::$root,
                'content' => self::$root . '/content',
                'site'    => self::$root . '/site',
                'cache'   => self::$root . '/cache',
            ],
            'options' => ['contentIndex.databasePath' => '/indexes/'],
            'collections' => [
                'rebuildTestPages' => fn () => site()->children()->listed()->filter(
                    fn (Page $page): bool => !in_array($page->slug(), self::$unwalked, true)
                ),
            ],
        ]);
    }

    protected function setUp(): void
    {
        self::$onRow = null;
        self::$failing = [];
        self::$rowless = [];
        self::$unwalked = [];
        $this->removeTree(self::$root . '/content');
        $this->removeTree(self::$root . '/site/indexes');
        mkdir(self::$root . '/content', 0777, true);
        App::instance()->impersonate('kirby');
    }

    public static function tearDownAfterClass(): void
    {
        restore_error_handler();
        restore_exception_handler();
    }

    public function testRebuildIndexesListedPagesAndRecordsTheRebuild(): void
    {
        $this->makePage('1_alpha', 'Alpha');
        $this->makePage('2_beta', 'Beta');
        $this->makePage('gamma', 'Gamma');
        $manager = $this->manager();

        $count = $manager->rebuildIndex($this->helper());

        $this->assertSame(2, $count);
        $this->assertEqualsCanonicalizing(['alpha', 'beta'], $manager->query()->getPageIds());
        $this->assertNotNull($manager->getStats()['last_rebuild']);
    }

    public function testRebuildRemovesARowWhosePageHasGone(): void
    {
        $this->makePage('1_alpha', 'Alpha');
        $manager = $this->manager();
        $this->insertRow('gone', 'Gone');

        $manager->rebuildIndex($this->helper());

        $this->assertSame(['alpha'], $manager->query()->getPageIds());
    }

    public function testRebuildKeepsARowForAPageTheWalkDidNotReach(): void
    {
        $this->makePage('1_alpha', 'Alpha');
        $this->makePage('2_late', 'Late');
        self::$unwalked = ['late'];
        $manager = $this->manager();
        $this->insertRow('late', 'Late');

        $manager->rebuildIndex($this->helper());

        $this->assertEqualsCanonicalizing(['alpha', 'late'], $manager->query()->getPageIds());
    }

    public function testRebuildRemovesTheRowOfAWalkedPageNoLongerIndexed(): void
    {
        $this->makePage('1_alpha', 'Alpha');
        $this->makePage('2_beta', 'Beta');
        $manager = $this->manager();
        $this->insertRow('beta', 'Beta');
        self::$rowless = ['beta'];

        $manager->rebuildIndex($this->helper());

        $this->assertSame(['alpha'], $manager->query()->getPageIds());
    }

    public function testAnotherWriterIsNotBlockedWhileTheRebuildReadsPages(): void
    {
        $this->makePage('1_alpha', 'Alpha');
        $this->makePage('2_beta', 'Beta');
        $manager = $this->manager();
        $written = false;
        self::$onRow = function (Page $page) use (&$written): void {
            if ($page->slug() !== 'beta') {
                return;
            }
            // a page save's hook, on its own connection, refusing to wait at all
            $other = new PDO('sqlite:' . $this->databaseFile());
            $other->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $other->exec('PRAGMA busy_timeout = 0');
            $other->exec("INSERT INTO content_rebuildtest (page_id, title) VALUES ('saved', 'Saved')");
            $written = true;
        };

        $manager->rebuildIndex($this->helper());

        $this->assertTrue($written);
    }

    public function testRebuildReplacesAStaleRowRatherThanDuplicatingIt(): void
    {
        $this->makePage('1_alpha', 'Alpha');
        $manager = $this->manager();
        $this->insertRow('alpha', 'Old title');

        $manager->rebuildIndex($this->helper());

        $rows = $manager->query()->get();
        $this->assertCount(1, $rows);
        $this->assertSame('Alpha', $rows[0]['title']);
    }

    public function testIndexPageKeepsTheOldRowWhenTheNewOneCannotBeBuilt(): void
    {
        $page = $this->makePage('1_alpha', 'Alpha');
        $manager = $this->manager();
        $manager->indexPage($page, $this->helper());
        self::$failing = ['alpha'];

        $this->assertFalse($manager->indexPage($page, $this->helper()));

        $this->assertSame(['alpha'], $manager->query()->getPageIds());
    }

    public function testIndexPageRemovesTheRowOfAPageNoLongerIndexed(): void
    {
        $page = $this->makePage('1_alpha', 'Alpha');
        $manager = $this->manager();
        $manager->indexPage($page, $this->helper());

        $manager->indexPage($page->changeStatus('unlisted'), $this->helper());

        $this->assertSame([], $manager->query()->getPageIds());
    }

    private function manager(): ContentIndexManager
    {
        return new ContentIndexManager(new class (self::class) extends ContentIndexDefinition {
            public function __construct(private readonly string $test)
            {
            }

            public function getName(): string
            {
                return 'rebuildtest';
            }

            public function getCollectionName(): string
            {
                return 'rebuildTestPages';
            }

            public function getTemplates(): array
            {
                return ['default'];
            }

            public function getColumns(): array
            {
                return ['title' => 'TEXT NOT NULL DEFAULT ""'];
            }

            public function getIndexes(): array
            {
                return [];
            }

            public function getRowData(Page $page, KirbyBaseHelper $helper): array
            {
                return ($this->test)::rowFor($page);
            }
        });
    }

    /**
     * The definition's row, with the hooks a test set.
     *
     * @return array<string, string>
     */
    public static function rowFor(Page $page): array
    {
        if (in_array($page->slug(), self::$failing, true)) {
            throw new RuntimeException('row data unavailable');
        }
        if (in_array($page->slug(), self::$rowless, true)) {
            return [];
        }
        if (self::$onRow !== null) {
            (self::$onRow)($page);
        }

        return ['page_id' => $page->id(), 'title' => $page->title()->value()];
    }

    private function helper(): KirbyBaseHelper
    {
        return $this->createStub(KirbyBaseHelper::class);
    }

    private function makePage(string $dir, string $title): Page
    {
        mkdir(self::$root . '/content/' . $dir, 0777, true);
        file_put_contents(self::$root . '/content/' . $dir . '/default.txt', "Title: $title\n");
        $slug = preg_replace('/^\d+_/', '', $dir);
        // a fresh site, so pages written to disk mid-class are seen
        App::instance()->site()->purge();

        return App::instance()->site()->find($slug);
    }

    private function databaseFile(): string
    {
        return self::$root . '/site/indexes/rebuildtest.sqlite';
    }

    private function insertRow(string $pageId, string $title): void
    {
        $database = new PDO('sqlite:' . $this->databaseFile());
        $database->prepare('INSERT INTO content_rebuildtest (page_id, title) VALUES (?, ?)')
            ->execute([$pageId, $title]);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
