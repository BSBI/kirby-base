<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\SearchQueryLogger;
use BSBI\WebBase\Testing\KirbyTestEnvironment;
use Kirby\Cms\App;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SearchQueryLogger.
 *
 * Boots a Kirby App against a copy of the search-log fixture content tree
 * (a single `search-log` page with the `search_log` template) so the logger
 * can create real child pages in a writable temp dir.
 *
 * Booted in setUpBeforeClass so the global-handler registration stays out of
 * the per-test risky-handler window, and so this App is the current Kirby
 * singleton for all fixture-based tests.
 */
final class SearchQueryLoggerTest extends TestCase
{
    private static App $kirby;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$kirby = KirbyTestEnvironment::bootWithContent(
            __DIR__ . '/../../fixtures/search-log-content',
            'search-query-logger'
        );
    }

    public function testDisabledLoggerWritesNothing(): void
    {
        $logger = new SearchQueryLogger(self::$kirby, self::$kirby->site(), false);

        $this->assertFalse($logger->log('orchid'));

        $searchLog = self::$kirby->site()->children()->template('search_log')->first();
        $this->assertNotNull($searchLog);
        $this->assertCount(0, $searchLog->children());
    }

    public function testEnabledLoggerWritesListedLogItem(): void
    {
        $logger = new SearchQueryLogger(self::$kirby, self::$kirby->site(), true);

        $this->assertTrue($logger->log('bluebell'));

        $searchLog = self::$kirby->site()->children()->template('search_log')->first();
        $this->assertNotNull($searchLog);

        $children = $searchLog->children();
        $this->assertCount(1, $children);

        $logItem = $children->first();
        $this->assertNotNull($logItem);
        $this->assertSame('search_log_item', $logItem->intendedTemplate()->name());
        $this->assertSame('bluebell', $logItem->content()->get('searchQuery')->value());
        $this->assertNotEmpty($logItem->content()->get('searchDate')->value());
        $this->assertTrue($logItem->isListed());
    }

    /**
     * Booting a new App replaces the Kirby singleton, so this must be the LAST
     * test in the class: PHPUnit runs methods in declaration order, and any
     * fixture-based test declared after this one would resolve pages against
     * the wrong (empty) App.
     */
    public function testNoSearchLogPageMeansNothingIsWritten(): void
    {
        $emptyKirby = KirbyTestEnvironment::boot('search-query-logger-empty');

        try {
            $logger = new SearchQueryLogger($emptyKirby, $emptyKirby->site(), true);

            $this->assertFalse($logger->log('orchid'));
            $this->assertCount(0, $emptyKirby->site()->children());
        } finally {
            // Booting inside a test registers global error/exception handlers,
            // which PHPUnit 12 reports as risky; unwind them before the test ends.
            restore_error_handler();
            restore_exception_handler();
        }
    }
}
