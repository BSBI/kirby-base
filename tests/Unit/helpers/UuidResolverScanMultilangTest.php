<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\UuidResolver;
use BSBI\WebBase\Testing\KirbyTestEnvironment;
use Kirby\Cms\App;
use Kirby\Cms\File;
use Kirby\Uuid\Uuid;
use PHPUnit\Framework\TestCase;

/**
 * The content scan (bsbi-web#945) on a multi-language site, where content files are
 * named `default.en.txt` and file metadata `pic.svg.en.txt`.
 */
final class UuidResolverScanMultilangTest extends TestCase
{
    private static App $kirby;

    public static function setUpBeforeClass(): void
    {
        self::$kirby = KirbyTestEnvironment::bootWithContent(
            dirname(__DIR__, 2) . '/fixtures/uuid-scan-multilang',
            'kirby-base-uuid-scan-multilang',
            [
                'options' => [
                    'cacheName' => 'bsbi',
                    'cache' => ['bsbi' => true, 'uuid' => true],
                    'languages' => true,
                ],
                'languages' => [
                    ['code' => 'en', 'name' => 'English', 'default' => true],
                    ['code' => 'cy', 'name' => 'Cymraeg'],
                ],
                'pageModels' => ['default' => ScanCountingPage::class],
            ]
        );
    }

    protected function setUp(): void
    {
        (new UuidResolver(self::$kirby))->forgetMisses();
        self::$kirby->cache('uuid')->flush();
        ScanCountingPage::$reads = [];
    }

    public function testFindsPagesAndFiles(): void
    {
        $resolver = new UuidResolver(self::$kirby);

        self::assertTrue(self::$kirby->multilang());
        self::assertSame('about', $resolver->page('page://pagemlabout00000')?->id());
        self::assertSame('about/plan', $resolver->page('page://pagemlplan000000')?->id());
        $file = $resolver->file('file://filemlpic0000000');
        self::assertInstanceOf(File::class, $file);
        self::assertSame('home/pic.svg', $file->id());
        self::assertTrue(Uuid::for('file://filemlpic0000000')?->isCached());
    }

    public function testAnUnknownUuidReadsNoPage(): void
    {
        self::assertNull((new UuidResolver(self::$kirby))->page('page://nosuchpage000000'));
        self::assertSame([], ScanCountingPage::$reads);
    }
}
