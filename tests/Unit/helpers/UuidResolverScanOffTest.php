<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\UuidResolver;
use BSBI\WebBase\Testing\KirbyTestEnvironment;
use Kirby\Cms\App;
use PHPUnit\Framework\TestCase;

/**
 * Without `uuidResolver.scanContent` (off by default) an uncached UUID goes through
 * core's walk of the site index, as before bsbi-web#945: virtual pages, built in code,
 * have no content files for the scan to find.
 */
final class UuidResolverScanOffTest extends TestCase
{
    private static App $kirby;

    public static function setUpBeforeClass(): void
    {
        self::$kirby = KirbyTestEnvironment::bootWithContent(
            dirname(__DIR__, 2) . '/fixtures/uuid-scan-content',
            'kirby-base-uuid-scan-off',
            [
                'options' => [
                    'cacheName' => 'bsbi',
                    'cache' => ['bsbi' => true, 'uuid' => true],
                ],
                'pageModels' => ['default' => ScanCountingPage::class, 'article' => ScanCountingPage::class],
            ]
        );
    }

    public function testCoreWalksTheIndex(): void
    {
        $resolver = new UuidResolver(self::$kirby);
        $resolver->forgetMisses();
        self::$kirby->cache('uuid')->flush();
        ScanCountingPage::$reads = [];

        self::assertNull($resolver->page('page://nosuchpage000000'));
        self::assertNotSame([], ScanCountingPage::$reads, 'core walks the index');
    }
}
