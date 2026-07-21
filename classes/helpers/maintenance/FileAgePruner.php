<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers\maintenance;

use DateTimeImmutable;

/**
 * Generic mtime-based pruner for a fixed set of named entries under a base directory.
 *
 * Only entries whose name appears in the caller's reclaimable list are ever considered;
 * a protected list is subtracted from that (protection always wins). An entry is removed
 * only when the newest file anywhere in its subtree is older than the retention window —
 * so a bulk directory touched recently (mid-import) is never deleted, honouring the
 * cleanup contract's age floor.
 *
 * The reclaimable/protected split is what makes this safe to point at a directory (like
 * bsbi-web's `src/import`) that mixes disposable bulk with git-tracked live data.
 *
 * @package BSBI\WebBase\helpers\maintenance
 */
final readonly class FileAgePruner
{
    /** How many sample lines to include in a preview. */
    private const int SAMPLE_LIMIT = 10;

    /**
     * @param DateTimeImmutable $now the reference "now" (injected so age is testable)
     */
    public function __construct(private DateTimeImmutable $now)
    {
    }

    /**
     * Compute what pruning would reclaim, without deleting anything.
     *
     * @param string $baseDir absolute path containing the named entries
     * @param array<int, string> $reclaimable entry names eligible for removal
     * @param array<int, string> $protected entry names that must never be removed
     * @param int $retentionDays remove only entries older than this many days
     * @return MaintenancePreview
     */
    public function preview(string $baseDir, array $reclaimable, array $protected, int $retentionDays): MaintenancePreview
    {
        $items = 0;
        $bytes = 0;
        $sample = [];

        foreach ($this->eligible($baseDir, $reclaimable, $protected, $retentionDays) as $name => $size) {
            $items++;
            $bytes += $size;
            if (count($sample) < self::SAMPLE_LIMIT) {
                $sample[] = sprintf('%s (%s)', $name, MaintenanceFilesystem::humanBytes($size));
            }
        }

        return new MaintenancePreview($items, $bytes, $sample);
    }

    /**
     * Delete every eligible entry. Blocking: completes in one call.
     *
     * @param string $baseDir absolute path containing the named entries
     * @param array<int, string> $reclaimable entry names eligible for removal
     * @param array<int, string> $protected entry names that must never be removed
     * @param int $retentionDays remove only entries older than this many days
     * @return MaintenanceRunResult
     */
    public function prune(string $baseDir, array $reclaimable, array $protected, int $retentionDays): MaintenanceRunResult
    {
        $processed = 0;
        $bytes = 0;

        foreach ($this->eligible($baseDir, $reclaimable, $protected, $retentionDays) as $name => $size) {
            if (MaintenanceFilesystem::delete($baseDir . '/' . $name)) {
                $processed++;
                $bytes += $size;
            }
        }

        return MaintenanceRunResult::completed($processed, $bytes);
    }

    /**
     * Yield eligible entries as name => size in bytes.
     *
     * @param string $baseDir absolute path containing the named entries
     * @param array<int, string> $reclaimable entry names eligible for removal
     * @param array<int, string> $protected entry names that must never be removed
     * @param int $retentionDays remove only entries older than this many days
     * @return iterable<string, int>
     */
    private function eligible(string $baseDir, array $reclaimable, array $protected, int $retentionDays): iterable
    {
        if (!is_dir($baseDir)) {
            return;
        }

        $cutoff = $this->now->modify('-' . $retentionDays . ' days')->getTimestamp();

        foreach ($reclaimable as $name) {
            if (in_array($name, $protected, true)) {
                continue;
            }

            $path = $baseDir . '/' . $name;
            if (!file_exists($path)) {
                continue;
            }

            if (MaintenanceFilesystem::newestFileMtime($path) >= $cutoff) {
                continue; // too fresh — age floor protects it
            }

            yield $name => MaintenanceFilesystem::size($path);
        }
    }
}
