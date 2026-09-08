<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\UuidResolver;
use BSBI\WebBase\Testing\KirbyTestEnvironment;
use Kirby\Cms\App;
use Kirby\Cms\File;
use PHPUnit\Framework\TestCase;

/**
 * UuidResolver on a site with no `cacheName` cache configured (bsbi-web#732):
 * Kirby hands back a NullCache, so nothing is remembered and behaviour equals core.
 */
final class UuidResolverNoCacheTest extends TestCase
{
    private static App $kirby;

    public static function setUpBeforeClass(): void
    {
        self::$kirby = KirbyTestEnvironment::bootWithContent(
            dirname(__DIR__, 2) . '/fixtures/uuid-content',
            'kirby-base-uuid-resolver-nocache',
            ['options' => ['uuidResolver' => ['missTtlSeconds' => 90]]]
        );
    }

    public function testMissTtlComesFromTheOption(): void
    {
        self::assertSame(90, (new UuidResolver(self::$kirby))->missTtlSeconds());
    }

    public function testNonPositiveOptionFallsBackToTheDefault(): void
    {
        // Cloning the app registers Kirby's error/exception handlers; pop them again so
        // PHPUnit does not flag the test as risky.
        $kirby = self::$kirby->clone(['options' => ['uuidResolver' => ['missTtlSeconds' => 0]]]);
        try {
            self::assertSame(UuidResolver::DEFAULT_MISS_TTL_SECONDS, (new UuidResolver($kirby))->missTtlSeconds());
        } finally {
            restore_error_handler();
            restore_exception_handler();
        }
    }

    public function testResolvesWithoutRememberingMisses(): void
    {
        $resolver = new UuidResolver(self::$kirby);

        self::assertNull($resolver->file('file://nosuchfile000000'));
        self::assertFalse($resolver->isKnownMiss('file://nosuchfile000000'), 'a NullCache remembers nothing');
        self::assertInstanceOf(File::class, $resolver->file('file://fileaaaaaaaaaaaa'));
        $resolver->forgetMisses();
    }
}
