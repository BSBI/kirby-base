<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers\maintenance;

use DateTimeImmutable;

/**
 * Filesystem engine for media cleanup: enumerates `media/pages/` page directories and deletes
 * derivative hash directories older than the retention window, in resumable chunks bounded by
 * a page-directory offset/limit.
 *
 * **Media is a disposable cache.** Kirby's originals live in `content/`; everything under
 * `media/pages/` is a regenerable derivative (thumbnails, responsive/format variants). Deleting
 * an old hash dir simply forces Kirby to lazily regenerate whatever is still requested — which
 * also sheds accumulated cruft (obsolete responsive widths, dead formats) that a "keep only the
 * live hash" approach cannot reach because it sits inside otherwise-current dirs. The retention
 * window is the only knob: it spares recently-generated (hot) media to bound the regeneration
 * load, and clears the cold long tail.
 *
 * **Hash-vs-page classification (safety-critical).** A page's media directory holds one hash dir
 * per file — named `<mediaToken>-<mtime>` where `mediaToken` is 10 hex chars (see
 * {@see \Kirby\Cms\File::mediaHash()}) — alongside subdirectories for its child pages. A child
 * dir is treated as a *hash dir* only when its name matches that pattern **and** it contains no
 * subdirectories of its own (real thumb dirs hold only variant files). This double guard means a
 * page path can never be misclassified as a hash dir and deleted.
 *
 * **Resumable-under-deletion.** Chunks delete from the same tree they enumerate, so `nextOffset`
 * advances only over *surviving* page dirs — those that still hold at least one hash dir after
 * the chunk. Fully-cleared page dirs drop out of the next enumeration from the front, so
 * surviving dirs keep their relative position and none are skipped.
 *
 * Enumeration re-walks the tree each call (readdir-only). For the intended use (a manual,
 * infrequent live reclaim) that is acceptable; a streaming cursor could replace it if the cost
 * ever bites.
 *
 * @package BSBI\WebBase\helpers\maintenance
 */
final readonly class MediaGarbageCollector
{
    /** A page-file media hash dir: 10 hex token, a hyphen, then the source mtime. */
    private const string HASH_DIR_PATTERN = '/^[0-9a-f]{10}-\d+$/';

    /** How many sample lines to include in a preview. */
    private const int SAMPLE_LIMIT = 10;

    /**
     * @param DateTimeImmutable $now the reference "now" (injected so the retention cutoff is testable)
     */
    public function __construct(private DateTimeImmutable $now)
    {
    }

    /**
     * Compute — without deleting anything — the old hash dirs and bytes a full run would reclaim.
     *
     * Runs off the deferred, time-limit-lifted preview endpoint (never inline in the dashboard
     * render), so sizing the stale dirs is affordable: it is bounded by the number of stale
     * files, which is fast at real scale. If a pathologically large tree ever overran, the
     * endpoint would surface a "preview failed" and the run (which reports its own reclaimed
     * bytes) would still work.
     *
     * @param string $mediaPagesDir absolute path to `media/pages`
     * @param MaintenanceOptions $options shared retention options
     * @param bool $wipeAll when true, ignore the retention window and count every hash dir (used
     *        by the staging blanket-wipe task; the age-gated live cleanup leaves it false)
     * @return MaintenancePreview count and bytes of stale dirs, with a sample
     */
    public function preview(string $mediaPagesDir, MaintenanceOptions $options, bool $wipeAll = false): MaintenancePreview
    {
        $cutoff = $this->cutoff($options->retentionDays);
        $items = 0;
        $bytes = 0;
        $sample = [];

        foreach ($this->enumeratePageDirs($mediaPagesDir) as $pageDir) {
            foreach ($this->hashDirsOf($pageDir['path']) as $hashDir) {
                if (!$wipeAll && $hashDir['mtime'] >= $cutoff) {
                    continue;
                }
                $items++;
                $size = MaintenanceFilesystem::size($pageDir['path'] . '/' . $hashDir['name']);
                $bytes += $size;
                if (count($sample) < self::SAMPLE_LIMIT) {
                    $sample[] = sprintf(
                        '%s/%s (%s)',
                        $pageDir['pageId'],
                        $hashDir['name'],
                        MaintenanceFilesystem::humanBytes($size),
                    );
                }
            }
        }

        return new MaintenancePreview($items, $bytes, $sample);
    }

    /**
     * Delete the old hash dirs for one chunk of page directories.
     *
     * @param string $mediaPagesDir absolute path to `media/pages`
     * @param MaintenanceOptions $options shared retention options
     * @param int $offset page-directory offset to resume from
     * @param int $limit maximum page directories to process (<= 0 = all remaining, single shot)
     * @param bool $wipeAll when true, ignore the retention window and delete every hash dir (used
     *        by the staging blanket-wipe task; the age-gated live cleanup leaves it false)
     * @return MaintenanceRunResult hash dirs deleted, bytes reclaimed, and the next offset
     */
    public function runChunk(string $mediaPagesDir, MaintenanceOptions $options, int $offset, int $limit, bool $wipeAll = false): MaintenanceRunResult
    {
        $cutoff = $this->cutoff($options->retentionDays);
        $pageDirs = $this->enumeratePageDirs($mediaPagesDir);
        $total = count($pageDirs);
        $end = $limit <= 0 ? $total : min($offset + $limit, $total);

        $processed = 0;
        $reclaimed = 0;
        $survivors = 0;

        for ($i = $offset; $i < $end; $i++) {
            $pageDir = $pageDirs[$i];
            $hashDirs = $this->hashDirsOf($pageDir['path']);
            $deleted = 0;

            foreach ($hashDirs as $hashDir) {
                if (!$wipeAll && $hashDir['mtime'] >= $cutoff) {
                    continue;
                }
                $full = $pageDir['path'] . '/' . $hashDir['name'];
                $reclaimed += MaintenanceFilesystem::size($full);
                if (MaintenanceFilesystem::delete($full)) {
                    $processed++;
                    $deleted++;
                }
            }

            // A page dir survives only if it still holds a hash dir (so it will reappear in the
            // next enumeration); otherwise it drops out and its empty shell is pruned.
            if ($deleted < count($hashDirs)) {
                $survivors++;
            } else {
                $this->pruneIfEmpty($pageDir['path'], $mediaPagesDir);
            }
        }

        $done = $limit <= 0 || $end >= $total;

        return new MaintenanceRunResult(
            $done,
            $processed,
            $reclaimed,
            $done ? 0 : $offset + $survivors,
        );
    }

    /**
     * The retention cutoff timestamp: dirs older than this (mtime <) are removable.
     *
     * @param int $retentionDays retention window in days
     * @return int unix timestamp
     */
    private function cutoff(int $retentionDays): int
    {
        return $this->now->setTime(0, 0)->modify('-' . $retentionDays . ' days')->getTimestamp();
    }

    /**
     * All page-media directories under `media/pages`, sorted by page id for a stable order.
     *
     * A page-media directory is any directory that *directly* contains at least one hash dir.
     * Directories that only contain child-page subdirectories are traversed but not themselves
     * emitted.
     *
     * @param string $mediaPagesDir absolute path to `media/pages`
     * @return array<int, array{path: string, pageId: string}>
     */
    private function enumeratePageDirs(string $mediaPagesDir): array
    {
        if (!is_dir($mediaPagesDir)) {
            return [];
        }

        $found = [];
        $this->collectPageDirs($mediaPagesDir, '', $found);
        usort($found, static fn (array $a, array $b): int => strcmp($a['pageId'], $b['pageId']));

        return $found;
    }

    /**
     * Recursively gather page-media directories.
     *
     * @param string $dir absolute path of the directory being scanned
     * @param string $pageId page id of $dir relative to `media/pages` ('' at the root)
     * @param array<int, array{path: string, pageId: string}> $found accumulator (by reference)
     */
    private function collectPageDirs(string $dir, string $pageId, array &$found): void
    {
        $hasHashDir = false;
        $subPaths = [];

        foreach ($this->childDirs($dir) as $name) {
            $childPath = $dir . '/' . $name;
            if ($this->isHashDir($childPath, $name)) {
                $hasHashDir = true;
            } else {
                $subPaths[] = $name;
            }
        }

        if ($hasHashDir && $pageId !== '') {
            $found[] = ['path' => $dir, 'pageId' => $pageId];
        }

        foreach ($subPaths as $name) {
            $childId = $pageId === '' ? $name : $pageId . '/' . $name;
            $this->collectPageDirs($dir . '/' . $name, $childId, $found);
        }
    }

    /**
     * The hash directories directly under a page-media directory, each with its mtime.
     *
     * @param string $pageDirPath absolute path to the page-media directory
     * @return array<int, array{name: string, mtime: int}>
     */
    private function hashDirsOf(string $pageDirPath): array
    {
        $dirs = [];
        foreach ($this->childDirs($pageDirPath) as $name) {
            $childPath = $pageDirPath . '/' . $name;
            if ($this->isHashDir($childPath, $name)) {
                $dirs[] = ['name' => $name, 'mtime' => (int) filemtime($childPath)];
            }
        }

        return $dirs;
    }

    /**
     * Whether a directory is a media hash dir: its name matches the token-mtime pattern and it holds
     * no *visible* subdirectory.
     *
     * A real page-file media dir holds variant files and, in newer Kirby, a hidden `.jobs/` directory
     * of pending thumb-generation jobs — so a dot-prefixed subdir is tolerated. A *visible*
     * subdirectory instead means this is a page path (holding child-page/hash subdirs) that merely
     * looks like a hash, and must never be treated as a hash dir (and thus deleted).
     *
     * @param string $path absolute path to the directory
     * @param string $name the directory's basename
     * @return bool
     */
    private function isHashDir(string $path, string $name): bool
    {
        if (preg_match(self::HASH_DIR_PATTERN, $name) !== 1) {
            return false;
        }

        foreach ($this->childDirs($path) as $child) {
            if (!str_starts_with($child, '.')) {
                return false; // a visible subdir ⇒ page path, not a hash dir
            }
        }

        return true;
    }

    /**
     * The immediate subdirectory names of a directory (skipping dot entries and files).
     *
     * @param string $dir absolute directory path
     * @return array<int, string>
     */
    private function childDirs(string $dir): array
    {
        $names = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (is_dir($dir . '/' . $entry)) {
                $names[] = $entry;
            }
        }

        return $names;
    }

    /**
     * Remove a now-empty page directory and any ancestor directories it empties, stopping at
     * (and never removing) the `media/pages` root.
     *
     * @param string $path absolute path of the emptied page directory
     * @param string $stopAt absolute path never to remove or ascend past
     */
    private function pruneIfEmpty(string $path, string $stopAt): void
    {
        $current = $path;
        while ($current !== $stopAt && is_dir($current) && $this->isEmptyDir($current)) {
            if (!@rmdir($current)) {
                return;
            }
            $current = dirname($current);
        }
    }

    /**
     * Whether a directory has no entries at all.
     *
     * @param string $dir absolute directory path
     * @return bool
     */
    private function isEmptyDir(string $dir): bool
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                return false;
            }
        }

        return true;
    }
}
