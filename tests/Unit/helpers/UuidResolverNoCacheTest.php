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
            'kirby-base-uuid-resolver-nocache'
        );
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
