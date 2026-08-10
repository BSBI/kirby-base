<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\SearchLogStore;
use BSBI\WebBase\helpers\SearchService;
use BSBI\WebBase\Testing\KirbyTestEnvironment;
use Kirby\Cms\App;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SearchService's SQLite-backed analytics methods.
 *
 * These read from the same `search.logDatabasePath`-resolved SQLite file that
 * KirbyBaseHelper::logSearchQuery() writes to, so the test opens a store at
 * that exact resolved path, inserts rows directly, and asserts that
 * SearchService reads them back — end to end, no Kirby content pages
 * involved anywhere.
 */
final class SearchServiceAnalyticsTest extends TestCase
{
    private static App $kirby;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$kirby = KirbyTestEnvironment::boot('search-service-analytics');
    }

    private function resolvedLogPath(): string
    {
        return self::$kirby->root('logs') . option('search.logDatabasePath', '/search/search-log.sqlite');
    }

    private function makeStore(): SearchLogStore
    {
        return SearchLogStore::open($this->resolvedLogPath());
    }

    protected function tearDown(): void
    {
        $path = $this->resolvedLogPath();
        foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    public function testGetTopSearchTermsReadsFromTheStore(): void
    {
        $store = $this->makeStore();
        $store->insert('Daisy', '2026-01-01 10:00:00');
        $store->insert('daisy', '2026-01-01 11:00:00');
        $store->insert('orchid', '2026-01-01 12:00:00');

        $service = new SearchService(self::$kirby->site(), self::$kirby);
        $topTerms = $service->getTopSearchTerms(20);

        $this->assertSame(
            [
                ['term' => 'daisy', 'count' => 2],
                ['term' => 'orchid', 'count' => 1],
            ],
            $topTerms
        );
    }

    public function testGetTopSearchKeywordsAppliesStopWordFiltering(): void
    {
        $store = $this->makeStore();
        // "the" and "of" are stop words and must be filtered out.
        $store->insert('the flowers of spring', '2026-01-01 10:00:00');
        $store->insert('spring flowers', '2026-01-01 11:00:00');

        $service = new SearchService(self::$kirby->site(), self::$kirby);
        $keywords = $service->getTopSearchKeywords(20);

        $keywordWords = array_column($keywords, 'keyword');
        $this->assertContains('flowers', $keywordWords);
        $this->assertContains('spring', $keywordWords);
        $this->assertNotContains('the', $keywordWords);
        $this->assertNotContains('of', $keywordWords);

        $flowersEntry = array_values(array_filter($keywords, fn ($k) => $k['keyword'] === 'flowers'))[0];
        $this->assertSame(2, $flowersEntry['count']);
    }

    public function testGetSearchAnalyticsSummaryReadsFromTheStore(): void
    {
        $store = $this->makeStore();
        $store->insert('daisy', '2026-01-01 10:00:00');
        $store->insert('orchid', '2026-01-02 11:00:00');

        $service = new SearchService(self::$kirby->site(), self::$kirby);
        $summary = $service->getSearchAnalyticsSummary();

        $this->assertSame(2, $summary['totalSearches']);
        $this->assertSame(2, $summary['uniqueTerms']);
        $this->assertSame('2026-01-01 10:00:00', $summary['dateRange']['from']);
        $this->assertSame('2026-01-02 11:00:00', $summary['dateRange']['to']);
    }

    public function testGetSearchAnalyticsSummaryOnEmptyLog(): void
    {
        // No inserts — the store file is created empty by makeStore()'s open().
        $this->makeStore();

        $service = new SearchService(self::$kirby->site(), self::$kirby);
        $summary = $service->getSearchAnalyticsSummary();

        $this->assertSame(
            [
                'totalSearches' => 0,
                'uniqueTerms' => 0,
                'dateRange' => ['from' => null, 'to' => null],
            ],
            $summary
        );
    }
}
