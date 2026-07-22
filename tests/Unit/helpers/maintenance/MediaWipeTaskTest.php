<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers\maintenance;

use BSBI\WebBase\helpers\maintenance\DeferredPreviewTask;
use BSBI\WebBase\helpers\maintenance\MaintenanceGuard;
use BSBI\WebBase\helpers\maintenance\MaintenanceGuardException;
use BSBI\WebBase\helpers\maintenance\MaintenanceOptions;
use BSBI\WebBase\helpers\maintenance\MediaWipeTask;
use Kirby\Cms\App;
use PHPUnit\Framework\TestCase;

/**
 * The generic blanket media-wipe task. Two behaviours matter beyond the underlying collector
 * (which is tested exhaustively in {@see MediaGarbageCollectorTest}):
 *   1. it is a **guarded** destructive task — preview() and run() must call the injected guard's
 *      assertPermitted() FIRST, so a refused environment deletes nothing; and
 *   2. it wipes **all** media regardless of age (retention window ignored).
 */
final class MediaWipeTaskTest extends TestCase
{
    private static App $kirby;
    private static string $mediaRoot;

    public static function setUpBeforeClass(): void
    {
        // Boot once: constructing the App registers global handlers PHPUnit would otherwise flag.
        $root = sys_get_temp_dir() . '/media-wipe-task-test';
        self::$mediaRoot = $root . '/media';
        self::$kirby = new App([
            'roots' => [
                'index' => $root,
                'media' => self::$mediaRoot,
            ],
        ]);
    }

    protected function setUp(): void
    {
        $this->rrm(self::$mediaRoot);
    }

    protected function tearDown(): void
    {
        $this->rrm(self::$mediaRoot);
    }

    private function rrm(string $path): void
    {
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->rrm($path . '/' . $entry);
                }
            }
            @rmdir($path);
        } elseif (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Create `media/pages/<pageId>/<hash>/image.jpg`, stamping the hash dir mtime to `now`
     * (recent), so a wipe that respected the retention window would KEEP it — proving wipeAll.
     *
     * @param string $pageId page id under media/pages
     * @param string $hash hash dir name (<token>-<mtime>)
     * @param int $bytes size of the variant file
     */
    private function makeRecentHashDir(string $pageId, string $hash, int $bytes = 100): void
    {
        $dir = self::$mediaRoot . '/pages/' . $pageId . '/' . $hash;
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/image-800x600.jpg', str_repeat('x', $bytes));
        // Leave mtime at "now" — a recent dir the age gate would spare.
    }

    private function permittingGuard(): MaintenanceGuard
    {
        return new class implements MaintenanceGuard {
            public function isPermitted(): bool
            {
                return true;
            }

            public function assertPermitted(): void
            {
            }
        };
    }

    private function refusingGuard(): MaintenanceGuard
    {
        return new class implements MaintenanceGuard {
            public function isPermitted(): bool
            {
                return false;
            }

            public function assertPermitted(): void
            {
                throw new MaintenanceGuardException('refused in test');
            }
        };
    }

    private function options(): MaintenanceOptions
    {
        return new MaintenanceOptions(30);
    }

    public function testHasStableKeyAndIsADeferredPreviewTask(): void
    {
        $task = new MediaWipeTask(self::$kirby, $this->permittingGuard());

        self::assertSame('media-wipe', $task->key());
        self::assertInstanceOf(DeferredPreviewTask::class, $task);
    }

    public function testRefusingGuardMakesRunThrowAndDeletesNothing(): void
    {
        $this->makeRecentHashDir('news', 'aaaaaaaaaa-1700000000', 500);
        $task = new MediaWipeTask(self::$kirby, $this->refusingGuard());

        try {
            $task->run($this->options());
            self::fail('Expected MaintenanceGuardException');
        } catch (MaintenanceGuardException) {
            // expected
        }

        self::assertFileExists(self::$mediaRoot . '/pages/news/aaaaaaaaaa-1700000000/image-800x600.jpg');
    }

    public function testRefusingGuardMakesPreviewThrowAndDeletesNothing(): void
    {
        $this->makeRecentHashDir('news', 'aaaaaaaaaa-1700000000', 500);
        $task = new MediaWipeTask(self::$kirby, $this->refusingGuard());

        $this->expectException(MaintenanceGuardException::class);

        try {
            $task->preview($this->options());
        } finally {
            self::assertFileExists(self::$mediaRoot . '/pages/news/aaaaaaaaaa-1700000000/image-800x600.jpg');
        }
    }

    public function testPermittingGuardWipesRecentMediaTheAgeGateWouldKeep(): void
    {
        $this->makeRecentHashDir('news', 'aaaaaaaaaa-1700000000', 500);
        $this->makeRecentHashDir('blog/2026/post', 'bbbbbbbbbb-1700000001', 300);
        $task = new MediaWipeTask(self::$kirby, $this->permittingGuard());

        $result = $task->run($this->options());

        self::assertTrue($result->done);
        self::assertSame(2, $result->processed);
        self::assertSame(800, $result->reclaimedBytes);
        self::assertDirectoryDoesNotExist(self::$mediaRoot . '/pages/news');
        self::assertDirectoryDoesNotExist(self::$mediaRoot . '/pages/blog');
    }

    public function testPermittingGuardPreviewCountsAllMediaRegardlessOfAge(): void
    {
        $this->makeRecentHashDir('news', 'aaaaaaaaaa-1700000000', 500);
        $this->makeRecentHashDir('blog/2026/post', 'bbbbbbbbbb-1700000001', 300);
        $task = new MediaWipeTask(self::$kirby, $this->permittingGuard());

        $preview = $task->preview($this->options());

        self::assertSame(2, $preview->items);
        self::assertSame(800, $preview->bytes);
    }
}
