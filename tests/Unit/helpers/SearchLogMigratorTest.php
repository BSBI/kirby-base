<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\SearchLogMigrator;
use BSBI\WebBase\helpers\SearchLogStore;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for SearchLogMigrator.
 *
 * Builds a temp content-tree fixture of `search_log_item` entry folders (the
 * same shape as the real `content/search-log/*` directory) and migrates it
 * into an in-memory SearchLogStore, without needing a Kirby App at all — the
 * migrator parses the Kirby txt content format directly.
 */
final class SearchLogMigratorTest extends TestCase
{
    private string $fixtureDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureDir = sys_get_temp_dir() . '/search-log-migrator-' . uniqid();
        mkdir($this->fixtureDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->fixtureDir);
        parent::tearDown();
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    private function makeStore(): SearchLogStore
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return new SearchLogStore($pdo);
    }

    /**
     * Writes a search_log_item content file in Kirby's txt format.
     *
     * @param string $folder Entry folder name (e.g. "1_2026-08-10-07-24-45")
     * @param string $filename Content filename (e.g. "search_log_item.en.txt")
     * @param string|null $query Searchquery field value, or null to omit the field
     * @param string|null $date Searchdate field value, or null to omit the field
     */
    private function writeEntry(string $folder, string $filename, ?string $query, ?string $date): void
    {
        $dir = $this->fixtureDir . '/' . $folder;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $fields = [];
        $fields[] = 'Title: ' . ($query ?? 'untitled');
        if ($query !== null) {
            $fields[] = 'Searchquery: ' . $query;
        }
        if ($date !== null) {
            $fields[] = 'Searchdate: ' . $date;
        }

        file_put_contents($dir . '/' . $filename, implode("\n\n----\n\n", $fields));
    }

    public function testMigratesEntriesHandlingLanguageVariantsAndSkipsMissingQuery(): void
    {
        // Folder 1: a language-suffixed file (.en.txt), has both fields.
        $this->writeEntry('1_2026-01-01-10-00-00', 'search_log_item.en.txt', 'daisy', '2026-01-01 10:00:00');

        // Folder 2: a plain .txt file, has both fields.
        $this->writeEntry('2_2026-01-02-11-00-00', 'search_log_item.txt', 'orchid', '2026-01-02 11:00:00');

        // Folder 3: missing Searchquery entirely — must be skipped.
        $this->writeEntry('3_2026-01-03-12-00-00', 'search_log_item.txt', null, '2026-01-03 12:00:00');

        $store = $this->makeStore();
        $migrator = new SearchLogMigrator($store);

        $result = $migrator->migrate($this->fixtureDir);

        $this->assertSame(['migrated' => 2, 'skipped' => 1], $result);
        $this->assertSame(2, $store->count());
        $this->assertSame(['daisy' => 1, 'orchid' => 1], $store->queryCounts());
    }

    public function testFallsBackToFolderNameDateWhenSearchdateMissing(): void
    {
        $this->writeEntry('1_2026-03-04-09-15-30', 'search_log_item.txt', 'bluebell', null);

        $store = $this->makeStore();
        $migrator = new SearchLogMigrator($store);

        $result = $migrator->migrate($this->fixtureDir);

        $this->assertSame(['migrated' => 1, 'skipped' => 0], $result);
        $topTerms = $store->topTerms(1);
        $this->assertSame('bluebell', $topTerms[0]['term']);

        $summary = $store->summary();
        $this->assertSame('2026-03-04 09:15:30', $summary['dateRange']['from']);
    }

    public function testSkipsEntryWithNeitherSearchdateNorParsableFolderName(): void
    {
        $this->writeEntry('not-a-date-folder', 'search_log_item.txt', 'ash', null);

        $store = $this->makeStore();
        $migrator = new SearchLogMigrator($store);

        $result = $migrator->migrate($this->fixtureDir);

        $this->assertSame(['migrated' => 0, 'skipped' => 1], $result);
        $this->assertSame(0, $store->count());
    }

    public function testTakesOnlyOneFileWhenAFolderHasMultipleLanguageFiles(): void
    {
        $this->writeEntry('1_2026-01-01-10-00-00', 'search_log_item.en.txt', 'daisy', '2026-01-01 10:00:00');
        $this->writeEntry('1_2026-01-01-10-00-00', 'search_log_item.txt', 'daisy', '2026-01-01 10:00:00');

        $store = $this->makeStore();
        $migrator = new SearchLogMigrator($store);

        $result = $migrator->migrate($this->fixtureDir);

        // Not 2 — the same folder must never be inserted twice.
        $this->assertSame(['migrated' => 1, 'skipped' => 0], $result);
        $this->assertSame(1, $store->count());
    }

    public function testSecondMigrateWithoutForceThrowsAndLeavesCountUnchanged(): void
    {
        $this->writeEntry('1_2026-01-01-10-00-00', 'search_log_item.txt', 'daisy', '2026-01-01 10:00:00');

        $store = $this->makeStore();
        $migrator = new SearchLogMigrator($store);
        $migrator->migrate($this->fixtureDir);

        $this->assertSame(1, $store->count());

        $this->expectException(RuntimeException::class);
        try {
            $migrator->migrate($this->fixtureDir);
        } finally {
            $this->assertSame(1, $store->count());
        }
    }

    public function testForcedMigrateDoesNotDoubleCount(): void
    {
        $this->writeEntry('1_2026-01-01-10-00-00', 'search_log_item.txt', 'daisy', '2026-01-01 10:00:00');
        $this->writeEntry('2_2026-01-02-11-00-00', 'search_log_item.txt', 'orchid', '2026-01-02 11:00:00');

        $store = $this->makeStore();
        $migrator = new SearchLogMigrator($store);
        $migrator->migrate($this->fixtureDir);

        $this->assertSame(2, $store->count());

        $result = $migrator->migrate($this->fixtureDir, true);

        $this->assertSame(['migrated' => 2, 'skipped' => 0], $result);
        $this->assertSame(2, $store->count());
    }
}
