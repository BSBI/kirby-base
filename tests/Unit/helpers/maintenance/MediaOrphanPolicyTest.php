<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers\maintenance;

use BSBI\WebBase\helpers\maintenance\MediaOrphanPolicy;
use BSBI\WebBase\helpers\maintenance\MediaOrphanTarget;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure media-orphan decision logic — the highest-risk piece of the
 * media GC, so it is tested against fixture hash-dir listings with a fixed "now".
 *
 * A page's media directory holds one hash dir per file, named `<mediaToken>-<mtime>`. The
 * policy is given, for one page, that listing plus the page's *live* valid-hash set (or
 * null when the page no longer resolves) and decides which dirs are orphaned — subject to
 * a retention floor so a just-orphaned dir (mid-deploy) is spared.
 */
final class MediaOrphanPolicyTest extends TestCase
{
    private const int RETENTION_DAYS = 30;

    private MediaOrphanPolicy $policy;

    /** Old enough to be past the 30-day floor (well before the 21 Jun 2026 cutoff). */
    private int $oldMtime;

    /** Newer than the floor (within the last 30 days) — must be spared by grace. */
    private int $recentMtime;

    protected function setUp(): void
    {
        // Fixed clock: 21 July 2026. Retention cutoff at 30 days = 21 June 2026 00:00.
        $now = new DateTimeImmutable('2026-07-21 09:30:00', new DateTimeZone('UTC'));
        $this->policy = new MediaOrphanPolicy($now);

        $this->oldMtime    = (new DateTimeImmutable('2026-05-01 00:00:00', new DateTimeZone('UTC')))->getTimestamp();
        $this->recentMtime = (new DateTimeImmutable('2026-07-20 00:00:00', new DateTimeZone('UTC')))->getTimestamp();
    }

    /**
     * @param array<int, array{name: string, mtime: int}> $dirs
     * @param array<int, string>|null $validHashes
     * @return array<int, MediaOrphanTarget>
     */
    private function decide(array $dirs, ?array $validHashes): array
    {
        return $this->policy->decide($dirs, $validHashes, self::RETENTION_DAYS)->deletions;
    }

    public function testNoDirsYieldsNoDeletions(): void
    {
        self::assertSame([], $this->decide([], ['abc123def0-1700000000']));
    }

    public function testCurrentHashDirsAreKept(): void
    {
        $dirs = [
            ['name' => 'abc123def0-1700000000', 'mtime' => $this->oldMtime],
            ['name' => 'ffff0000aa-1700000001', 'mtime' => $this->oldMtime],
        ];
        $valid = ['abc123def0-1700000000', 'ffff0000aa-1700000001'];

        self::assertSame([], $this->decide($dirs, $valid));
    }

    public function testMissingPageDeletesAllOldHashDirsAsMissingSource(): void
    {
        $dirs = [
            ['name' => 'abc123def0-1700000000', 'mtime' => $this->oldMtime],
            ['name' => 'ffff0000aa-1700000001', 'mtime' => $this->oldMtime],
        ];

        // null valid-set = the page no longer resolves → category 1.
        $deletions = $this->decide($dirs, null);

        self::assertCount(2, $deletions);
        foreach ($deletions as $target) {
            self::assertSame(MediaOrphanTarget::REASON_MISSING_SOURCE, $target->reason);
        }
        self::assertEqualsCanonicalizing(
            ['abc123def0-1700000000', 'ffff0000aa-1700000001'],
            array_map(static fn (MediaOrphanTarget $t): string => $t->name, $deletions),
        );
    }

    public function testStaleHashDirNotInValidSetIsDeleted(): void
    {
        $dirs = [
            ['name' => 'abc123def0-1700000000', 'mtime' => $this->oldMtime], // current
            ['name' => 'abc123def0-1699000000', 'mtime' => $this->oldMtime], // older mtime, superseded
        ];
        // Only the current hash is live; the older-mtime dir is a stale leftover.
        $valid = ['abc123def0-1700000000'];

        $deletions = $this->decide($dirs, $valid);

        self::assertCount(1, $deletions);
        self::assertSame('abc123def0-1699000000', $deletions[0]->name);
        self::assertSame(MediaOrphanTarget::REASON_STALE_HASH, $deletions[0]->reason);
    }

    public function testDeletedFileLeavesNoLiveHashSoDirIsStale(): void
    {
        // The page resolves but has no files at all (all deleted) → every dir is orphaned.
        $dirs = [
            ['name' => 'abc123def0-1700000000', 'mtime' => $this->oldMtime],
        ];

        $deletions = $this->decide($dirs, []);

        self::assertCount(1, $deletions);
        self::assertSame(MediaOrphanTarget::REASON_STALE_HASH, $deletions[0]->reason);
    }

    public function testRecentlyOrphanedDirWithinRetentionFloorIsSpared(): void
    {
        $dirs = [
            ['name' => 'abc123def0-1699000000', 'mtime' => $this->recentMtime],
        ];

        // Orphaned (not in the live set) but younger than the 30-day floor → keep.
        self::assertSame([], $this->decide($dirs, ['abc123def0-1700000000']));
    }

    public function testRecentlyOrphanedDirIsSparedEvenWhenPageMissing(): void
    {
        $dirs = [
            ['name' => 'abc123def0-1700000000', 'mtime' => $this->recentMtime],
        ];

        // Mid-deploy: the page vanished moments ago; the floor protects a just-orphaned dir.
        self::assertSame([], $this->decide($dirs, null));
    }

    public function testMixedListSplitsCorrectlyByCategoryAndFloor(): void
    {
        $dirs = [
            ['name' => 'live000000-1700000000', 'mtime' => $this->oldMtime],    // current → keep
            ['name' => 'live000000-1699000000', 'mtime' => $this->oldMtime],    // stale, old → delete
            ['name' => 'gone111111-1698000000', 'mtime' => $this->oldMtime],    // file gone, old → delete
            ['name' => 'fresh22222-1699500000', 'mtime' => $this->recentMtime], // stale but young → keep
        ];
        $valid = ['live000000-1700000000'];

        $names = array_map(
            static fn (MediaOrphanTarget $t): string => $t->name,
            $this->decide($dirs, $valid),
        );

        self::assertEqualsCanonicalizing(
            ['live000000-1699000000', 'gone111111-1698000000'],
            $names,
        );
    }
}
