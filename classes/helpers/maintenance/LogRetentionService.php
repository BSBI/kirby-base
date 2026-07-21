<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers\maintenance;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Filesystem wrapper around {@see LogRetentionPolicy}.
 *
 * Walks a logs directory, applies the retention policy to each `*.log` file, and rewrites
 * changed files atomically (temp file + rename). Directories that hold non-log state —
 * above all `content-indexes/`, the expensive SQLite content indexes — are skipped
 * entirely.
 *
 * @package BSBI\WebBase\helpers\maintenance
 */
final readonly class LogRetentionService
{
    /** Subdirectory names never descended into (SQLite indexes, etc.). */
    private const array EXCLUDED_DIRS = ['content-indexes'];

    /** How many sample lines to include in a preview. */
    private const int SAMPLE_LIMIT = 10;

    /**
     * @param LogRetentionPolicy $policy the pure decision engine
     */
    public function __construct(private LogRetentionPolicy $policy)
    {
    }

    /**
     * Compute what pruning would reclaim, without writing anything.
     *
     * @param string $logsDir absolute path to the logs directory
     * @param MaintenanceOptions $options shared retention/size options
     * @return MaintenancePreview files that would change and bytes that would be freed
     */
    public function preview(string $logsDir, MaintenanceOptions $options): MaintenancePreview
    {
        $items = 0;
        $bytes = 0;
        $sample = [];

        foreach ($this->logFiles($logsDir) as $file) {
            $result = $this->evaluate($file, $options);
            if ($result === null || $result->reclaimedBytes() === 0) {
                continue;
            }

            $items++;
            $bytes += $result->reclaimedBytes();
            if (count($sample) < self::SAMPLE_LIMIT) {
                $sample[] = sprintf(
                    '%s: %s → %s (%s)',
                    $file->getFilename(),
                    MaintenanceFilesystem::humanBytes($result->originalBytes),
                    MaintenanceFilesystem::humanBytes($result->newBytes),
                    $result->mode,
                );
            }
        }

        return new MaintenancePreview($items, $bytes, $sample);
    }

    /**
     * Prune every eligible log file. Blocking: completes in one call.
     *
     * @param string $logsDir absolute path to the logs directory
     * @param MaintenanceOptions $options shared retention/size options
     * @return MaintenanceRunResult processed file count and bytes reclaimed
     */
    public function run(string $logsDir, MaintenanceOptions $options): MaintenanceRunResult
    {
        $processed = 0;
        $bytes = 0;

        foreach ($this->logFiles($logsDir) as $file) {
            $result = $this->evaluate($file, $options);
            if ($result === null || $result->reclaimedBytes() === 0) {
                continue;
            }

            if ($this->writeAtomically($file->getPathname(), $result->content)) {
                $processed++;
                $bytes += $result->reclaimedBytes();
            }
        }

        return MaintenanceRunResult::completed($processed, $bytes);
    }

    /**
     * Read a log file and run it through the policy.
     *
     * @param SplFileInfo $file the log file
     * @param MaintenanceOptions $options shared retention/size options
     * @return LogRewriteResult|null the rewrite result, or null if the file is unreadable
     */
    private function evaluate(SplFileInfo $file, MaintenanceOptions $options): ?LogRewriteResult
    {
        $content = @file_get_contents($file->getPathname());
        if ($content === false) {
            return null;
        }

        return $this->policy->apply($content, $options->retentionDays, $options->logSizeCapBytes);
    }

    /**
     * Iterate the `*.log` files under a directory, skipping excluded subdirectories.
     *
     * @param string $logsDir absolute path to the logs directory
     * @return iterable<SplFileInfo>
     */
    private function logFiles(string $logsDir): iterable
    {
        if (!is_dir($logsDir)) {
            return;
        }

        $directoryIterator = new RecursiveDirectoryIterator(
            $logsDir,
            FilesystemIterator::SKIP_DOTS,
        );

        $filter = new \RecursiveCallbackFilterIterator(
            $directoryIterator,
            static function (SplFileInfo $current): bool {
                if ($current->isDir()) {
                    return !in_array($current->getFilename(), self::EXCLUDED_DIRS, true);
                }

                return strtolower($current->getExtension()) === 'log';
            },
        );

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator($filter) as $file) {
            if ($file->isFile()) {
                yield $file;
            }
        }
    }

    /**
     * Replace a file's content atomically via a temp file in the same directory + rename.
     *
     * @param string $path absolute path to the file
     * @param string $content the new content
     * @return bool whether the write succeeded
     */
    private function writeAtomically(string $path, string $content): bool
    {
        $temp = $path . '.tmp-' . getmypid() . '-' . uniqid('', true);

        if (@file_put_contents($temp, $content) === false) {
            return false;
        }

        if (!@rename($temp, $path)) {
            @unlink($temp);
            return false;
        }

        return true;
    }
}
