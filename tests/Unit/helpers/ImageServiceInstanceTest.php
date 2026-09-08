<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\ImageService;
use BSBI\WebBase\Testing\KirbyTestEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * `ImageService::instance()` is the shared instance block snippets use, so
 * they do not assemble the service and its field reader by hand. It follows
 * the same rule as `UuidResolver::instance()`: one instance per Kirby App,
 * replaced when a different App is current.
 *
 * Both Apps are booted up front: booting inside a test registers global
 * handlers that PHPUnit reports as risky.
 */
final class ImageServiceInstanceTest extends TestCase
{
    private static ImageService $forFirstApp;

    private static ImageService $forFirstAppAgain;

    private static ImageService $forSecondApp;

    public static function setUpBeforeClass(): void
    {
        KirbyTestEnvironment::boot('kirby-base-image-service-instance-' . uniqid());
        self::$forFirstApp      = ImageService::instance();
        self::$forFirstAppAgain = ImageService::instance();

        KirbyTestEnvironment::boot('kirby-base-image-service-instance-' . uniqid());
        self::$forSecondApp = ImageService::instance();
    }

    public function testReturnsTheSameInstanceWhileTheAppIsCurrent(): void
    {
        self::assertSame(self::$forFirstApp, self::$forFirstAppAgain);
        self::assertSame(self::$forSecondApp, ImageService::instance());
    }

    public function testReturnsANewInstanceWhenTheAppChanges(): void
    {
        self::assertNotSame(self::$forFirstApp, self::$forSecondApp);
    }
}
