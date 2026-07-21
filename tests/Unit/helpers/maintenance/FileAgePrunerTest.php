<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers\maintenance;

use BSBI\WebBase\helpers\maintenance\FileAgePruner;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the generic mtime-based directory pruner used by the site-specific import
 * cleanup: it must remove only named, old, non-protected entries, and treat the newest
 * file in a subtree as the age of the whole subtree (so a recently-touched bulk dir is
 * never deleted).
 */
final class FileAgePrunerTest extends TestCase
{
    private const int RETENTION_DAYS = 30;

    private string $dir;
    private FileAgePruner $pruner;
    private int $now;

    protected function setUp(): void
    {
        $nowDate = new DateTimeImmutable('2026-07-21 09:30:00', new DateTimeZone('UTC'));
        $this->now = $nowDate->getTimestamp();
        $this->pruner = new FileAgePruner($nowDate);

        $this->dir = sys_get_temp_dir() . '/file-age-pruner-' . uniqid('', true);
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrm($this->dir);
    }

    private function rrm(string $path): void
    {
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->rrm($path . '/' . $entry);
                }
            }
            rmdir($path);
        } elseif (is_file($path)) {
            unlink($path);
        }
    }

    private function ageDays(int $days): int
    {
        return $this->now - ($days * 86400);
    }

    /**
     * @param string $relPath path relative to the base dir
     * @param int $mtime modification time to stamp
     * @param int $bytes size of the file
     */
    private function makeFile(string $relPath, int $mtime, int $bytes = 10): void
    {
        $full = $this->dir . '/' . $relPath;
        $parent = dirname($full);
        if (!is_dir($parent)) {
            mkdir($parent, 0777, true);
        }
        file_put_contents($full, str_repeat('x', $bytes));
        touch($full, $mtime);
    }

    public function testOldReclaimableDirIsRemovedAndCounted(): void
    {
        $this->makeFile('Flora/data.txt', $this->ageDays(90), 100);

        $result = $this->pruner->prune($this->dir, ['Flora'], [], self::RETENTION_DAYS);

        self::assertTrue($result->done);
        self::assertSame(1, $result->processed);
        self::assertSame(100, $result->reclaimedBytes);
        self::assertDirectoryDoesNotExist($this->dir . '/Flora');
    }

    public function testRecentReclaimableDirIsKept(): void
    {
        $this->makeFile('Flora/data.txt', $this->ageDays(2), 100);

        $result = $this->pruner->prune($this->dir, ['Flora'], [], self::RETENTION_DAYS);

        self::assertSame(0, $result->processed);
        self::assertDirectoryExists($this->dir . '/Flora');
    }

    public function testProtectedNameIsNeverRemovedEvenWhenOld(): void
    {
        $this->makeFile('family/key.json', $this->ageDays(365), 100);

        // 'family' both listed and protected: protection must win.
        $result = $this->pruner->prune($this->dir, ['family', 'Flora'], ['family'], self::RETENTION_DAYS);

        self::assertSame(0, $result->processed);
        self::assertDirectoryExists($this->dir . '/family');
    }

    public function testUnlistedNameIsIgnored(): void
    {
        // 'Watsonia' exists and is old, but is not in the reclaimable list.
        $this->makeFile('Watsonia/data.txt', $this->ageDays(365), 100);

        $result = $this->pruner->prune($this->dir, ['Flora'], [], self::RETENTION_DAYS);

        self::assertSame(0, $result->processed);
        self::assertDirectoryExists($this->dir . '/Watsonia');
    }

    public function testSubtreeAgeIsTheNewestFile(): void
    {
        // One ancient file, one fresh file: the fresh one keeps the whole dir alive.
        $this->makeFile('image_files/old.jpg', $this->ageDays(400), 50);
        $this->makeFile('image_files/new.jpg', $this->ageDays(1), 50);

        $result = $this->pruner->prune($this->dir, ['image_files'], [], self::RETENTION_DAYS);

        self::assertSame(0, $result->processed);
        self::assertDirectoryExists($this->dir . '/image_files');
    }

    public function testOldReclaimableSingleFileIsRemoved(): void
    {
        $this->makeFile('blogger.xml', $this->ageDays(90), 200);

        $result = $this->pruner->prune($this->dir, ['blogger.xml'], [], self::RETENTION_DAYS);

        self::assertSame(1, $result->processed);
        self::assertSame(200, $result->reclaimedBytes);
        self::assertFileDoesNotExist($this->dir . '/blogger.xml');
    }

    public function testAbsentEntriesAreNoOp(): void
    {
        $result = $this->pruner->prune($this->dir, ['Flora', 'Watsonia', 'blogger.xml'], [], self::RETENTION_DAYS);

        self::assertTrue($result->done);
        self::assertSame(0, $result->processed);
        self::assertSame(0, $result->reclaimedBytes);
    }

    public function testPreviewDoesNotDelete(): void
    {
        $this->makeFile('Flora/data.txt', $this->ageDays(90), 100);
        $this->makeFile('Watsonia/data.txt', $this->ageDays(90), 250);

        $preview = $this->pruner->preview($this->dir, ['Flora', 'Watsonia'], [], self::RETENTION_DAYS);

        self::assertSame(2, $preview->items);
        self::assertSame(350, $preview->bytes);
        self::assertDirectoryExists($this->dir . '/Flora');
        self::assertDirectoryExists($this->dir . '/Watsonia');
    }

    public function testMissingBaseDirIsEmptyPreview(): void
    {
        $preview = $this->pruner->preview($this->dir . '/nope', ['Flora'], [], self::RETENTION_DAYS);

        self::assertSame(0, $preview->items);
        self::assertSame(0, $preview->bytes);
    }
}
