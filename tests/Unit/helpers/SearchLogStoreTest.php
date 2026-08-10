<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\SearchLogStore;
use Kirby\Exception\InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Tests for SearchLogStore.
 *
 * Constructed directly around an in-memory PDO connection (no file I/O, no
 * Kirby App needed) — the constructor itself ensures the schema exists, so
 * SearchLogStore::open() (the file-backed factory used in production) is not
 * required to exercise the SQL behaviour under test. One exception:
 * testOpenSetsABusyTimeout() exercises open() directly against a temp file,
 * since the busy-timeout pragma is only set there.
 */
final class SearchLogStoreTest extends TestCase
{
    private function makeStore(): SearchLogStore
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return new SearchLogStore($pdo);
    }

    private function pdoOf(SearchLogStore $store): PDO
    {
        $property = new ReflectionProperty(SearchLogStore::class, 'pdo');

        return $property->getValue($store);
    }

    public function testSchemaIsCreatedOnConstruction(): void
    {
        $store = $this->makeStore();

        // count() would throw if the search_log table did not exist.
        $this->assertSame(0, $store->count());
    }

    public function testInsertAndCount(): void
    {
        $store = $this->makeStore();

        $store->insert('daisy', '2026-01-01 10:00:00');
        $store->insert('orchid', '2026-01-01 11:00:00');

        $this->assertSame(2, $store->count());
    }

    public function testTopTermsFoldsCaseAndCountsAsOneTerm(): void
    {
        $store = $this->makeStore();

        $store->insert('Daisy', '2026-01-01 10:00:00');
        $store->insert('daisy', '2026-01-01 11:00:00');
        $store->insert('DAISY', '2026-01-01 12:00:00');

        $topTerms = $store->topTerms(20);

        $this->assertCount(1, $topTerms);
        $this->assertSame('daisy', $topTerms[0]['term']);
        $this->assertSame(3, $topTerms[0]['count']);
    }

    public function testTopTermsOrderingIsDeterministic(): void
    {
        $store = $this->makeStore();

        // 'orchid' appears twice, 'bluebell' once, 'ash' once — ties broken alphabetically.
        $store->insert('orchid', '2026-01-01 10:00:00');
        $store->insert('orchid', '2026-01-01 11:00:00');
        $store->insert('bluebell', '2026-01-01 12:00:00');
        $store->insert('ash', '2026-01-01 13:00:00');

        $topTerms = $store->topTerms(20);

        $this->assertSame(
            [
                ['term' => 'orchid', 'count' => 2],
                ['term' => 'ash', 'count' => 1],
                ['term' => 'bluebell', 'count' => 1],
            ],
            $topTerms
        );
    }

    public function testTopTermsRespectsLimit(): void
    {
        $store = $this->makeStore();

        $store->insert('one', '2026-01-01 10:00:00');
        $store->insert('two', '2026-01-01 11:00:00');
        $store->insert('three', '2026-01-01 12:00:00');

        $topTerms = $store->topTerms(2);

        $this->assertCount(2, $topTerms);
    }

    public function testQueryCountsReturnsLowercasedQueryToCountMap(): void
    {
        $store = $this->makeStore();

        $store->insert('Daisy', '2026-01-01 10:00:00');
        $store->insert('daisy', '2026-01-01 11:00:00');
        $store->insert('orchid', '2026-01-01 12:00:00');

        $this->assertSame(
            ['daisy' => 2, 'orchid' => 1],
            $store->queryCounts()
        );
    }

    public function testSummaryOnEmptyLog(): void
    {
        $store = $this->makeStore();

        $this->assertSame(
            [
                'totalSearches' => 0,
                'uniqueTerms' => 0,
                'dateRange' => ['from' => null, 'to' => null],
            ],
            $store->summary()
        );
    }

    public function testSummaryWithData(): void
    {
        $store = $this->makeStore();

        $store->insert('Daisy', '2026-01-01 10:00:00');
        $store->insert('daisy', '2026-01-02 09:00:00');
        $store->insert('orchid', '2026-01-03 15:30:00');

        $summary = $store->summary();

        $this->assertSame(3, $summary['totalSearches']);
        $this->assertSame(2, $summary['uniqueTerms']);
        $this->assertSame('2026-01-01 10:00:00', $summary['dateRange']['from']);
        $this->assertSame('2026-01-03 15:30:00', $summary['dateRange']['to']);
    }

    public function testPurgeOlderThanRemovesOnlyOlderRowsAndReturnsCount(): void
    {
        $store = $this->makeStore();

        $store->insert('old one', '2020-01-01 00:00:00');
        $store->insert('old two', '2020-06-01 00:00:00');
        $store->insert('recent', '2026-01-01 00:00:00');

        $deleted = $store->purgeOlderThan('2025-01-01 00:00:00');

        $this->assertSame(2, $deleted);
        $this->assertSame(1, $store->count());
        $this->assertSame(['recent' => 1], $store->queryCounts());
    }

    public function testDeleteAllRemovesEverything(): void
    {
        $store = $this->makeStore();

        $store->insert('one', '2026-01-01 10:00:00');
        $store->insert('two', '2026-01-01 11:00:00');

        $deleted = $store->deleteAll();

        $this->assertSame(2, $deleted);
        $this->assertSame(0, $store->count());
    }

    public function testRunInTransactionReturnsCallbackResult(): void
    {
        $store = $this->makeStore();

        $result = $store->runInTransaction(function () use ($store) {
            $store->insert('within transaction', '2026-01-01 10:00:00');
            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertSame(1, $store->count());
    }

    public function testRunInTransactionRollsBackOnThrow(): void
    {
        $store = $this->makeStore();

        try {
            $store->runInTransaction(function () use ($store) {
                $store->insert('will be rolled back', '2026-01-01 10:00:00');
                throw new \RuntimeException('boom');
            });
            $this->fail('Expected exception was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame(0, $store->count());
    }

    /**
     * open() sets a busy timeout so a write that collides with another
     * connection retries for a few seconds instead of failing immediately
     * with "database is locked" — cheap to assert directly since it is only
     * set on the file-backed path, not on a bare in-memory PDO.
     */
    public function testOpenSetsABusyTimeout(): void
    {
        $path = sys_get_temp_dir() . '/search-log-store-busy-timeout-' . uniqid() . '.sqlite';

        try {
            $store = SearchLogStore::open($path);

            $busyTimeout = $this->pdoOf($store)->query('PRAGMA busy_timeout')->fetchColumn();

            $this->assertSame(3000, (int) $busyTimeout);
        } finally {
            foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    /**
     * `search.logDatabasePath` is admin config, not end-user input, but a
     * traversal value (`../../etc/whatever`) would still make open() mkdir
     * and write outside the intended logs directory. The guard must reject
     * it before any filesystem or PDO call happens.
     */
    public function testOpenRejectsPathContainingDotDot(): void
    {
        $base = sys_get_temp_dir() . '/search-log-store-traversal-' . uniqid();
        $escapedDir = dirname($base) . '/search-log-store-traversal-escaped-' . uniqid();
        $path = $base . '/../' . basename($escapedDir) . '/evil.sqlite';

        try {
            SearchLogStore::open($path);
            $this->fail('Expected InvalidArgumentException was not thrown');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('..', $e->getMessage());
        }

        $this->assertDirectoryDoesNotExist($base);
        $this->assertDirectoryDoesNotExist($escapedDir);
    }
}
