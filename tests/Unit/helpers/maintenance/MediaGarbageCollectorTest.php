<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers\maintenance;

use BSBI\WebBase\helpers\maintenance\MaintenanceFilesystem;
use BSBI\WebBase\helpers\maintenance\MaintenanceOptions;
use BSBI\WebBase\helpers\maintenance\MediaGarbageCollector;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the media garbage collector: enumeration, hash-vs-page classification,
 * age-gated deletion, chunk boundaries and resume-after-deletion — exercised against a real
 * temporary `media/pages/` tree.
 *
 * Media is treated as a disposable, regenerable cache: the collector simply deletes derivative
 * hash directories older than the retention window and lets Kirby lazily regenerate whatever is
 * still needed. There is no page/hash resolution — the retention window is the only knob.
 */
final class MediaGarbageCollectorTest extends TestCase
{
    private const int RETENTION_DAYS = 30;

    private string $root;
    private int $oldMtime;
    private int $recentMtime;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/media-gc-test-' . uniqid('', true);
        mkdir($this->root, 0777, true);

        // now = 21 Jul 2026 → 30-day cutoff = 21 Jun 2026.
        $this->oldMtime    = (new DateTimeImmutable('2026-05-01 00:00:00', new DateTimeZone('UTC')))->getTimestamp();
        $this->recentMtime = (new DateTimeImmutable('2026-07-20 00:00:00', new DateTimeZone('UTC')))->getTimestamp();
    }

    protected function tearDown(): void
    {
        MaintenanceFilesystem::delete($this->root);
    }

    /**
     * Build a hash directory `media/pages/<pageId>/<hash>/` holding one variant file of a
     * given size, then stamp the hash dir's mtime.
     *
     * @param string $pageId page id (may be nested, e.g. news/2024/article)
     * @param string $hash the hash dir name (`<token>-<mtime>`)
     * @param int $mtime mtime to stamp on the hash dir
     * @param int $bytes size of the single variant file written inside
     */
    private function makeHashDir(string $pageId, string $hash, int $mtime, int $bytes = 100): void
    {
        $dir = $this->root . '/' . $pageId . '/' . $hash;
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/image-800x600.jpg', str_repeat('x', $bytes));
        touch($dir, $mtime);
    }

    private function collector(): MediaGarbageCollector
    {
        $now = new DateTimeImmutable('2026-07-21 09:30:00', new DateTimeZone('UTC'));
        return new MediaGarbageCollector($now);
    }

    private function options(): MaintenanceOptions
    {
        return new MaintenanceOptions(self::RETENTION_DAYS);
    }

    private function exists(string $relative): bool
    {
        return file_exists($this->root . '/' . $relative);
    }

    public function testEmptyTreePreviewsAndRunsClean(): void
    {
        $collector = $this->collector();

        $preview = $collector->preview($this->root, $this->options());
        self::assertSame(0, $preview->items);
        self::assertSame(0, $preview->bytes);

        $result = $collector->runChunk($this->root, $this->options(), 0, 0);
        self::assertTrue($result->done);
        self::assertSame(0, $result->processed);
    }

    public function testDeletesOldHashDirsAndKeepsRecentOnes(): void
    {
        $this->makeHashDir('news', 'aaaaaaaaaa-1700000000', $this->oldMtime, 500);    // old → delete
        $this->makeHashDir('news', 'bbbbbbbbbb-1700000001', $this->recentMtime, 300); // recent → keep

        $collector = $this->collector();

        // The preview counts and sizes the old dirs it would remove.
        $preview = $collector->preview($this->root, $this->options());
        self::assertSame(1, $preview->items);
        self::assertSame(500, $preview->bytes);

        $result = $collector->runChunk($this->root, $this->options(), 0, 0);
        self::assertTrue($result->done);
        self::assertSame(1, $result->processed);
        self::assertSame(500, $result->reclaimedBytes);

        self::assertFalse($this->exists('news/aaaaaaaaaa-1700000000'));
        self::assertTrue($this->exists('news/bbbbbbbbbb-1700000001/image-800x600.jpg'));
    }

    public function testFullyClearedPageDirIsPrunedWithAncestors(): void
    {
        // A nested page whose only hash dir is old; the intermediate dir has none of its own.
        $this->makeHashDir('old-section/child', 'cccccccccc-1698000000', $this->oldMtime, 400);

        $result = $this->collector()->runChunk($this->root, $this->options(), 0, 0);

        self::assertSame(1, $result->processed);
        self::assertFalse($this->exists('old-section/child'));
        self::assertFalse($this->exists('old-section')); // empty ancestor pruned
    }

    public function testPagePathLookingLikeHashIsNotTreatedAsHashDir(): void
    {
        // A page whose slug coincidentally matches the hash pattern; it is a real page dir
        // (it contains hash-dir subdirectories), so it must be recursed into, never deleted as
        // a hash dir itself — even when its own mtime is old.
        $this->makeHashDir('abcdef0123-9999999999', 'dddddddddd-1698000000', $this->oldMtime, 250);   // old inner
        $this->makeHashDir('abcdef0123-9999999999', 'eeeeeeeeee-1700000000', $this->recentMtime, 120); // recent inner
        touch($this->root . '/abcdef0123-9999999999', $this->oldMtime);

        $result = $this->collector()->runChunk($this->root, $this->options(), 0, 0);

        self::assertSame(1, $result->processed); // only the old inner hash dir
        self::assertFalse($this->exists('abcdef0123-9999999999/dddddddddd-1698000000'));
        self::assertTrue($this->exists('abcdef0123-9999999999/eeeeeeeeee-1700000000/image-800x600.jpg'));
        self::assertTrue($this->exists('abcdef0123-9999999999')); // page path container survives
    }

    public function testChunkedRunProcessesEveryPageAndTerminates(): void
    {
        // Six pages, each one old hash dir; a small chunk limit forces resumption.
        for ($i = 0; $i < 6; $i++) {
            $this->makeHashDir('gone' . $i, 'eeeeeeeeee-169900000' . $i, $this->oldMtime, 100);
        }

        $collector = $this->collector();
        $options = $this->options();

        $offset = 0;
        $totalProcessed = 0;
        $iterations = 0;
        do {
            $result = $collector->runChunk($this->root, $options, $offset, 2);
            $totalProcessed += $result->processed;
            $offset = $result->nextOffset;
            self::assertLessThan(20, ++$iterations, 'chunk loop failed to terminate');
        } while (!$result->done);

        self::assertSame(6, $totalProcessed);
        for ($i = 0; $i < 6; $i++) {
            self::assertFalse($this->exists('gone' . $i), 'gone' . $i . ' should be deleted');
        }
    }

    public function testResumeSkipsRecentSurvivorsWithoutMissingOldDirs(): void
    {
        // Interleave survivors (recent, kept) with old dirs so a naive shrinking-list offset
        // would skip or double-process. Loop with limit 1.
        for ($i = 0; $i < 4; $i++) {
            $this->makeHashDir('keep' . $i, 'aaaaaaaaaa-170000000' . $i, $this->recentMtime, 10);
            $this->makeHashDir('drop' . $i, 'bbbbbbbbbb-169900000' . $i, $this->oldMtime, 50);
        }

        $collector = $this->collector();
        $options = $this->options();

        $offset = 0;
        $processed = 0;
        $iterations = 0;
        do {
            $result = $collector->runChunk($this->root, $options, $offset, 1);
            $processed += $result->processed;
            $offset = $result->nextOffset;
            self::assertLessThan(50, ++$iterations, 'loop failed to terminate');
        } while (!$result->done);

        self::assertSame(4, $processed); // exactly the four old dirs
        for ($i = 0; $i < 4; $i++) {
            self::assertTrue($this->exists('keep' . $i . '/aaaaaaaaaa-170000000' . $i . '/image-800x600.jpg'));
            self::assertFalse($this->exists('drop' . $i));
        }
    }

    // --- wipe-all mode (blanket staging wipe: no age gate) ------------------

    public function testWipeAllDeletesRecentDirsThatTheAgeGateWouldKeep(): void
    {
        $this->makeHashDir('news', 'aaaaaaaaaa-1700000000', $this->oldMtime, 500);    // old
        $this->makeHashDir('news', 'bbbbbbbbbb-1700000001', $this->recentMtime, 300); // recent

        // wipeAll ignores the retention window entirely — both go.
        $result = $this->collector()->runChunk($this->root, $this->options(), 0, 0, true);

        self::assertTrue($result->done);
        self::assertSame(2, $result->processed);
        self::assertSame(800, $result->reclaimedBytes);
        self::assertFalse($this->exists('news/aaaaaaaaaa-1700000000'));
        self::assertFalse($this->exists('news/bbbbbbbbbb-1700000001'));
        self::assertFalse($this->exists('news')); // emptied page dir pruned
    }

    public function testWipeAllPreviewCountsEveryHashDirRegardlessOfAge(): void
    {
        $this->makeHashDir('news', 'aaaaaaaaaa-1700000000', $this->oldMtime, 500);
        $this->makeHashDir('news', 'bbbbbbbbbb-1700000001', $this->recentMtime, 300);

        $preview = $this->collector()->preview($this->root, $this->options(), true);

        self::assertSame(2, $preview->items);
        self::assertSame(800, $preview->bytes);
    }

    public function testWipeAllStillPreservesPagePathContainers(): void
    {
        // Even wiping everything, a page path that merely looks like a hash must not be deleted as
        // one — the walk recurses into it and wipes the real hash dirs inside.
        $this->makeHashDir('abcdef0123-9999999999', 'dddddddddd-1698000000', $this->recentMtime, 250);

        $result = $this->collector()->runChunk($this->root, $this->options(), 0, 0, true);

        self::assertSame(1, $result->processed);
        self::assertFalse($this->exists('abcdef0123-9999999999/dddddddddd-1698000000'));
    }

    public function testWipeAllChunkedProcessesEveryPageAndTerminates(): void
    {
        // Mix recent + old across several pages; a small chunk forces resumption. All must go.
        for ($i = 0; $i < 6; $i++) {
            $mtime = $i % 2 === 0 ? $this->recentMtime : $this->oldMtime;
            $this->makeHashDir('page' . $i, 'cccccccccc-169900000' . $i, $mtime, 100);
        }

        $collector = $this->collector();
        $options = $this->options();

        $offset = 0;
        $processed = 0;
        $iterations = 0;
        do {
            $result = $collector->runChunk($this->root, $options, $offset, 2, true);
            $processed += $result->processed;
            $offset = $result->nextOffset;
            self::assertLessThan(20, ++$iterations, 'wipe chunk loop failed to terminate');
        } while (!$result->done);

        self::assertSame(6, $processed);
        for ($i = 0; $i < 6; $i++) {
            self::assertFalse($this->exists('page' . $i));
        }
    }
}
