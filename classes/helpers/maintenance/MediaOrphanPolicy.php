<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers\maintenance;

use DateTimeImmutable;

/**
 * Pure, filesystem-free media-orphan decision logic — the highest-risk piece of the media
 * GC, kept Kirby-free so it can be exhaustively unit-tested before running on ~45 GB of
 * live media.
 *
 * A page's media directory holds one hash dir per file, named `<mediaToken>-<mtime>` (see
 * {@see \Kirby\Cms\File::mediaHash()} — a salt-keyed HMAC, **not** `crc32(filename)`). The
 * policy is given, for a single page, that directory listing plus the page's *live* set of
 * valid hashes — or `null` when the page no longer resolves — and decides which dirs are
 * orphaned:
 *
 * - **null valid-set** ⇒ the page is gone; every dir is {@see MediaOrphanTarget::REASON_MISSING_SOURCE}.
 * - **dir name absent from a non-null set** ⇒ the source file was deleted/renamed or its
 *   mtime changed; the dir is {@see MediaOrphanTarget::REASON_STALE_HASH}.
 *
 * In both cases a **retention floor** spares any dir whose own mtime is newer than
 * `retentionDays`, so a just-orphaned dir (mid-deploy / mid-rebuild) is never deleted.
 *
 * @package BSBI\WebBase\helpers\maintenance
 */
final readonly class MediaOrphanPolicy
{
    /**
     * @param DateTimeImmutable $now the reference "now" (injected so the retention floor is testable)
     */
    public function __construct(private DateTimeImmutable $now)
    {
    }

    /**
     * Decide which of one page's hash directories are safe to delete.
     *
     * @param array<int, array{name: string, mtime: int}> $dirs the page's hash dirs (name + mtime)
     * @param array<int, string>|null $validHashes the page's live hash-dir names, or null if the page is gone
     * @param int $retentionDays spare any orphaned dir newer than this many days
     * @return MediaOrphanDecision the dirs judged safe to remove
     */
    public function decide(array $dirs, ?array $validHashes, int $retentionDays): MediaOrphanDecision
    {
        $cutoff = $this->now->setTime(0, 0)->modify('-' . $retentionDays . ' days')->getTimestamp();
        $pageMissing = $validHashes === null;
        $valid = $pageMissing ? [] : array_flip($validHashes);

        $deletions = [];
        foreach ($dirs as $dir) {
            // Live dir under a resolved page — always keep.
            if (!$pageMissing && isset($valid[$dir['name']])) {
                continue;
            }

            // Orphaned, but spared while still inside the retention floor (mid-deploy grace).
            if ($dir['mtime'] >= $cutoff) {
                continue;
            }

            $reason = $pageMissing
                ? MediaOrphanTarget::REASON_MISSING_SOURCE
                : MediaOrphanTarget::REASON_STALE_HASH;
            $deletions[] = new MediaOrphanTarget($dir['name'], $reason);
        }

        return new MediaOrphanDecision($deletions);
    }
}
