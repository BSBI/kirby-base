<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers\maintenance;

use BSBI\WebBase\helpers\maintenance\CacheClearTask;
use BSBI\WebBase\helpers\maintenance\MaintenanceOptions;
use BSBI\WebBase\Testing\KirbyTestEnvironment;
use Kirby\Cms\App;
use PHPUnit\Framework\TestCase;

/**
 * The Cache task empties Kirby's cache root but keeps the `uuid` lookup cache
 * (bsbi-web#734): clearing it never fixes anything and makes every reference
 * walk the site once on its next lookup.
 */
final class CacheClearTaskTest extends TestCase
{
    private static App $kirby;
    private static string $cacheRoot;

    public static function setUpBeforeClass(): void
    {
        self::$kirby = KirbyTestEnvironment::boot('kirby-base-cache-clear-' . uniqid());
        self::$cacheRoot = (string) self::$kirby->root('cache');
    }

    protected function setUp(): void
    {
        foreach (['pages/home', 'uuid/pa', 'bsbi'] as $dir) {
            @mkdir(self::$cacheRoot . '/' . $dir, 0777, true);
        }
        file_put_contents(self::$cacheRoot . '/pages/home/index.cache', str_repeat('p', 1000));
        file_put_contents(self::$cacheRoot . '/uuid/pa/geaaaaaaaaaaaa.cache', str_repeat('u', 400));
        file_put_contents(self::$cacheRoot . '/bsbi/uuid-misses.cache', str_repeat('m', 100));
    }

    public function testPreviewExcludesTheUuidCacheAndSaysSo(): void
    {
        $preview = (new CacheClearTask(self::$kirby))->preview(new MaintenanceOptions());

        self::assertSame(1, $preview->items);
        self::assertSame(1100, $preview->bytes, 'pages + bsbi caches; the 400-byte uuid cache is not counted');
        self::assertNotEmpty(array_filter($preview->sample, fn (string $line) => str_contains($line, 'UUID lookup cache')));
    }

    public function testRunKeepsTheUuidCache(): void
    {
        $result = (new CacheClearTask(self::$kirby))->run(new MaintenanceOptions());

        self::assertTrue($result->done);
        self::assertSame(1100, $result->reclaimedBytes);
        self::assertFileDoesNotExist(self::$cacheRoot . '/pages/home/index.cache');
        self::assertFileDoesNotExist(self::$cacheRoot . '/bsbi/uuid-misses.cache');
        self::assertFileExists(self::$cacheRoot . '/uuid/pa/geaaaaaaaaaaaa.cache');
    }

    public function testDescriptionMentionsTheKeptUuidCache(): void
    {
        self::assertStringContainsString('UUID', (new CacheClearTask(self::$kirby))->description());
    }
}
