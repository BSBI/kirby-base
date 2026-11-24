<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\UuidResolver;
use BSBI\WebBase\Testing\KirbyTestEnvironment;
use Kirby\Cms\App;
use Kirby\Cms\File;
use Kirby\Cms\Files;
use Kirby\Cms\Page;
use Kirby\Content\Content;
use Kirby\Uuid\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A UUID that isn't in Kirby's cache is found by scanning the content files, not by
 * core's walk of the whole site index (bsbi-web#945). The walk builds every page and
 * reads every content file; on 10,000+ pages it can need more than 256 MB, and for a
 * broken link it ran before the miss could be recorded.
 */
final class UuidResolverScanTest extends TestCase
{
    private static App $kirby;

    public static function setUpBeforeClass(): void
    {
        self::$kirby = KirbyTestEnvironment::bootWithContent(
            dirname(__DIR__, 2) . '/fixtures/uuid-scan-content',
            'kirby-base-uuid-scan',
            [
                'options' => ['cacheName' => 'bsbi', 'cache' => ['bsbi' => true, 'uuid' => true]],
                'pageModels' => [
                    'default' => ScanCountingPage::class,
                    'article' => ScanCountingPage::class,
                ],
            ]
        );
    }

    protected function setUp(): void
    {
        $this->resolver()->forgetMisses();
        self::$kirby->cache('uuid')->flush();
        ScanCountingPage::$reads = [];
    }

    private function resolver(): UuidResolver
    {
        return new UuidResolver(self::$kirby);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function pages(): array
    {
        return [
            'listed page'               => ['page://pagehome00000000', 'home'],
            'nested page'               => ['page://pagechild0000000', 'section/child'],
            'unlisted page'             => ['page://pageunlisted0000', 'section/unlisted'],
            'draft'                     => ['page://pagedraft0000000', 'section/draft'],
        ];
    }

    #[DataProvider('pages')]
    public function testFindsAnUncachedPageWithoutReadingOtherPages(string $uuid, string $id): void
    {
        $page = $this->resolver()->page($uuid);

        self::assertInstanceOf(Page::class, $page);
        self::assertSame($id, $page->id());
        self::assertSame([], $this->unrelatedReads($id), 'pages other than the one found were read');
        self::assertTrue(Uuid::for($uuid)?->isCached(), 'the UUID is cached, as core would cache it');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function files(): array
    {
        return [
            'a page file'   => ['file://filehomepic00000', 'home/pic.svg'],
            'a draft file'  => ['file://filedraftdoc0000', 'section/draft/doc.svg'],
            'a site file'   => ['file://filesitelogo0000', 'logo.svg'],
        ];
    }

    #[DataProvider('files')]
    public function testFindsAnUncachedFileWithoutReadingOtherPages(string $uuid, string $id): void
    {
        $file = $this->resolver()->file($uuid);

        self::assertInstanceOf(File::class, $file);
        self::assertSame($id, $file->id());
        self::assertSame([], $this->unrelatedReads(dirname($id)), 'pages other than the file\'s were read');
        self::assertTrue(Uuid::for($uuid)?->isCached());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unknown(): array
    {
        return [
            'page' => ['page://nosuchpage000000'],
            'file' => ['file://nosuchfile000000'],
            // Unsaved Panel changes repeat a UUID; they aren't a page of their own.
            'only in a _changes folder' => ['page://pagechangesonly0'],
        ];
    }

    #[DataProvider('unknown')]
    public function testAnUnknownUuidIsAMissWithoutReadingAnyPage(string $uuid): void
    {
        $resolver = $this->resolver();
        $model = Uuid::is($uuid, 'page') ? $resolver->page($uuid) : $resolver->file($uuid);

        self::assertNull($model);
        self::assertTrue($resolver->isKnownMiss($uuid));
        self::assertSame([], ScanCountingPage::$reads, 'the site index was walked');
    }

    public function testALineThatOnlyLooksLikeTheUuidIsPassedOver(): void
    {
        // The decoy page's text has a line "Uuid: pagelookalike000", and comes first.
        $page = $this->resolver()->page('page://pagelookalike000');

        self::assertInstanceOf(Page::class, $page);
        self::assertSame('lookalike', $page->id());
    }

    /**
     * Reads of pages that are neither `$id` nor one of its ancestors.
     *
     * @return list<string>
     */
    private function unrelatedReads(string $id): array
    {
        $allowed = [];
        $parts = $id === '.' ? [] : explode('/', $id);
        while ($parts !== []) {
            $allowed[] = implode('/', $parts);
            array_pop($parts);
        }

        return array_values(array_filter(
            ScanCountingPage::$reads,
            static fn (string $read): bool => !in_array(explode(' ', $read)[0], $allowed, true)
        ));
    }
}

/**
 * Records every page whose content or files are read.
 */
class ScanCountingPage extends Page
{
    /** @var list<string> */
    public static array $reads = [];

    public function content(string|null $languageCode = null): Content
    {
        self::$reads[] = $this->id();

        return parent::content($languageCode);
    }

    public function files(): Files
    {
        self::$reads[] = $this->id() . ' files';

        return parent::files();
    }
}
