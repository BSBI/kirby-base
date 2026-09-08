<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers\maintenance;

use BSBI\WebBase\helpers\maintenance\MaintenanceOptions;
use BSBI\WebBase\helpers\maintenance\UuidCachePopulateTask;
use BSBI\WebBase\helpers\UuidResolver;
use BSBI\WebBase\Testing\KirbyTestEnvironment;
use Kirby\Cms\App;
use Kirby\Uuid\Uuid;
use PHPUnit\Framework\TestCase;

/**
 * Tests for UuidCachePopulateTask (bsbi-web#732): after a run every page and
 * file UUID in the content tree is a cache hit, and remembered misses are gone.
 */
final class UuidCachePopulateTaskTest extends TestCase
{
    private static App $kirby;

    public static function setUpBeforeClass(): void
    {
        self::$kirby = KirbyTestEnvironment::bootWithContent(
            dirname(__DIR__, 3) . '/fixtures/uuid-content',
            'kirby-base-uuid-populate',
            ['options' => ['cacheName' => 'bsbi', 'cache' => ['bsbi' => true, 'uuid' => true]]]
        );
    }

    public function testPreviewCountsPagesAndFiles(): void
    {
        $preview = (new UuidCachePopulateTask(self::$kirby))->preview(new MaintenanceOptions());

        self::assertSame(3, $preview->items, 'two pages and one file');
        self::assertSame(['2 page(s) and 1 file(s)'], $preview->sample);
        self::assertSame('Would write 3 page and file UUID(s) to the lookup cache', $preview->summary);
        self::assertSame('No pages or files to cache', $preview->emptySummary);
    }

    public function testRunPopulatesTheCacheAndForgetsMisses(): void
    {
        self::$kirby->cache('uuid')->flush();
        $resolver = new UuidResolver(self::$kirby);
        $resolver->recordMiss('file://fileaaaaaaaaaaaa');
        self::assertFalse(Uuid::for('page://pagebbbbbbbbbbbb')->isCached());

        $result = (new UuidCachePopulateTask(self::$kirby))->run(new MaintenanceOptions());

        self::assertTrue($result->done);
        self::assertSame(3, $result->processed);
        self::assertTrue(Uuid::for('page://pageaaaaaaaaaaaa')->isCached());
        self::assertTrue(Uuid::for('page://pagebbbbbbbbbbbb')->isCached());
        self::assertTrue(Uuid::for('file://fileaaaaaaaaaaaa')->isCached());
        self::assertFalse($resolver->isKnownMiss('file://fileaaaaaaaaaaaa'));
    }
}
