<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers\maintenance;

/**
 * Immutable options shared by every maintenance task.
 *
 * A single {@see $retentionDays} window governs all tasks (the universal "age floor"
 * of the cleanup contract), and {@see $logSizeCapBytes} bounds log files that cannot be
 * date-pruned because they contain no dated entries.
 *
 * @package BSBI\WebBase\helpers\maintenance
 */
final readonly class MaintenanceOptions
{
    public const int DEFAULT_RETENTION_DAYS = 30;
    public const int DEFAULT_LOG_SIZE_CAP_BYTES = 262144; // 256 KB

    /**
     * @param int $retentionDays remove eligible items only when older than this many days
     * @param int $logSizeCapBytes keep at most this many bytes of a log with no dated entries
     */
    public function __construct(
        public int $retentionDays = self::DEFAULT_RETENTION_DAYS,
        public int $logSizeCapBytes = self::DEFAULT_LOG_SIZE_CAP_BYTES,
    ) {
    }

    /**
     * Build options from raw request input, clamping to safe bounds.
     *
     * @param int|null $retentionDays requested retention window, or null for the default
     * @return self
     */
    public static function fromRetentionDays(?int $retentionDays): self
    {
        if ($retentionDays === null) {
            return new self();
        }

        // Never allow a zero/negative window (would delete everything) — floor at 1 day.
        return new self(max(1, $retentionDays));
    }
}
