<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers\maintenance;

use BSBI\WebBase\helpers\maintenance\DanglingReferenceAuditTask;
use BSBI\WebBase\helpers\maintenance\MaintenanceOptions;
use BSBI\WebBase\Testing\KirbyTestEnvironment;
use Kirby\Cms\App;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DanglingReferenceAuditTask (bsbi-web#732).
 *
 * The fixture's home page references one file and one page that exist and one
 * of each that do not, and site.txt references the missing page too; only the
 * three references with no target are dangling.
 */
final class DanglingReferenceAuditTaskTest extends TestCase
{
    private static App $kirby;

    public static function setUpBeforeClass(): void
    {
        self::$kirby = KirbyTestEnvironment::bootWithContent(
            dirname(__DIR__, 3) . '/fixtures/uuid-content',
            'kirby-base-dangling-audit'
        );
    }

    public function testFindsOnlyTheReferencesWithNoTarget(): void
    {
        $task = new DanglingReferenceAuditTask(self::$kirby);

        self::assertSame(
            ['home' => ['file://nosuchfile000000', 'page://nosuchpage000000'], 'site' => ['page://nosuchpage000000']],
            $task->dangling()
        );
    }

    public function testPreviewCountsAndSamples(): void
    {
        $preview = (new DanglingReferenceAuditTask(self::$kirby))->preview(new MaintenanceOptions());

        self::assertSame(3, $preview->items);
        self::assertSame(0, $preview->bytes);
        self::assertSame(['home: file://nosuchfile000000, page://nosuchpage000000', 'site: page://nosuchpage000000'], $preview->sample);
    }

    public function testRunWritesTheLogAndDeletesNothing(): void
    {
        $before = count(iterator_to_array(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::$kirby->root('content'), \FilesystemIterator::SKIP_DOTS))));

        $result = (new DanglingReferenceAuditTask(self::$kirby))->run(new MaintenanceOptions());

        self::assertTrue($result->done);
        self::assertSame(3, $result->processed);
        $log = (string) file_get_contents(self::$kirby->root('logs') . '/' . DanglingReferenceAuditTask::LOG_FILE . '.log');
        self::assertStringContainsString('3 dangling reference(s) on 2 page(s):', $log);
        self::assertStringContainsString('home: file://nosuchfile000000, page://nosuchpage000000', $log);

        $after = count(iterator_to_array(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::$kirby->root('content'), \FilesystemIterator::SKIP_DOTS))));
        self::assertSame($before, $after);
    }

    public function testSymlinkedDirectoriesAreNotFollowed(): void
    {
        $root = self::$kirby->root('content');
        $outside = sys_get_temp_dir() . '/kirby-base-audit-outside-' . uniqid();
        mkdir($outside, 0777, true);
        file_put_contents($outside . '/default.txt', "Title: Outside\n\n----\n\nImage: - file://outsidefile00000\n");
        symlink($outside, $root . '/linked');

        try {
            $dangling = (new DanglingReferenceAuditTask(self::$kirby))->dangling();
            self::assertArrayNotHasKey('linked', $dangling);
        } finally {
            unlink($root . '/linked');
            unlink($outside . '/default.txt');
            rmdir($outside);
        }
    }

    public function testPageIdsDropSortingPrefixesAndDrafts(): void
    {
        $root = self::$kirby->root('content');
        mkdir($root . '/3_learn/_drafts/2_guide/_changes', 0777, true);
        file_put_contents($root . '/3_learn/_drafts/2_guide/default.txt', "Title: Guide\n\n----\n\nImage: - file://gonefile00000000\n\n----\n\nUuid: pageccccccccccc1\n");
        file_put_contents($root . '/3_learn/_drafts/2_guide/_changes/default.txt', "Title: Guide\n\n----\n\nImage: - file://gonefile00000001\n\n----\n\nUuid: pageccccccccccc1\n");

        try {
            $dangling = (new DanglingReferenceAuditTask(self::$kirby))->dangling();
            self::assertSame(['file://gonefile00000000', 'file://gonefile00000001'], $dangling['learn/guide'] ?? null, 'the unsaved-changes version counts against the same page');
        } finally {
            unlink($root . '/3_learn/_drafts/2_guide/_changes/default.txt');
            rmdir($root . '/3_learn/_drafts/2_guide/_changes');
            unlink($root . '/3_learn/_drafts/2_guide/default.txt');
            rmdir($root . '/3_learn/_drafts/2_guide');
            rmdir($root . '/3_learn/_drafts');
            rmdir($root . '/3_learn');
        }
    }
}
