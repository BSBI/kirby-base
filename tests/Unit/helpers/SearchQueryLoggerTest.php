<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\SearchLogStore;
use BSBI\WebBase\helpers\SearchQueryLogger;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SearchQueryLogger.
 *
 * The logger is now a thin wrapper around SearchLogStore: it no longer
 * touches Kirby content pages at all, so it is exercised entirely against an
 * in-memory SQLite store with no Kirby App involved.
 */
final class SearchQueryLoggerTest extends TestCase
{
    private function makeStore(): SearchLogStore
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return new SearchLogStore($pdo);
    }

    public function testDisabledLoggerWritesNothing(): void
    {
        $store = $this->makeStore();
        $logger = new SearchQueryLogger($store, false, 24);

        $this->assertFalse($logger->log('orchid'));
        $this->assertSame(0, $store->count());
    }

    public function testEnabledLoggerWritesARow(): void
    {
        $store = $this->makeStore();
        $logger = new SearchQueryLogger($store, true, 24);

        $this->assertTrue($logger->log('bluebell'));

        $this->assertSame(1, $store->count());
        $topTerms = $store->topTerms(1);
        $this->assertSame('bluebell', $topTerms[0]['term']);
        $this->assertSame(1, $topTerms[0]['count']);
    }

    /**
     * Retention greater than zero purges rows older than the cutoff on every log() call.
     */
    public function testLoggingPurgesRowsOlderThanRetention(): void
    {
        $store = $this->makeStore();
        // Insert a row well outside any real retention window.
        $store->insert('ancient query', '2000-01-01 00:00:00');

        $logger = new SearchQueryLogger($store, true, 24);
        $this->assertTrue($logger->log('fresh query'));

        // Only the fresh query (from this log() call) should remain.
        $this->assertSame(1, $store->count());
        $this->assertSame(['fresh query' => 1], $store->queryCounts());
    }

    /**
     * A retention of zero months means "keep forever" — no purge happens.
     */
    public function testZeroRetentionMonthsDisablesPurge(): void
    {
        $store = $this->makeStore();
        $store->insert('ancient query', '2000-01-01 00:00:00');

        $logger = new SearchQueryLogger($store, true, 0);
        $this->assertTrue($logger->log('fresh query'));

        $this->assertSame(2, $store->count());
    }

    /**
     * A query longer than the 1000-character cap is truncated before insert,
     * so a repeated max-length query string can't be used to bloat the log file.
     */
    public function testLongQueryIsTruncatedTo1000Characters(): void
    {
        $store = $this->makeStore();
        $logger = new SearchQueryLogger($store, true, 24);

        $longQuery = str_repeat('a', 1500);
        $this->assertTrue($logger->log($longQuery));

        $counts = $store->queryCounts();
        $this->assertCount(1, $counts);
        $storedQuery = array_key_first($counts);
        $this->assertSame(1000, mb_strlen($storedQuery));
        $this->assertSame(str_repeat('a', 1000), $storedQuery);
    }

    /**
     * A query at or under the cap is stored unchanged.
     */
    public function testNormalLengthQueryIsStoredUnchanged(): void
    {
        $store = $this->makeStore();
        $logger = new SearchQueryLogger($store, true, 24);

        $this->assertTrue($logger->log('bluebell'));

        $this->assertSame(['bluebell' => 1], $store->queryCounts());
    }
}
