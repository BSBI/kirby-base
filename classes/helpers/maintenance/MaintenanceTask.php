<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers\maintenance;

/**
 * The uniform contract every maintenance cleanup implements so the Panel can treat them
 * identically: preview → confirm → run → report.
 *
 * All tasks share one policy — remove an item only if it is (a) no longer needed AND
 * (b) older than {@see MaintenanceOptions::$retentionDays}. The "(a) no longer needed"
 * test differs by task kind (age is the whole test for logs/import; orphan-ness for
 * media), but the interface is the same.
 *
 * @package BSBI\WebBase\helpers\maintenance
 */
interface MaintenanceTask
{
    /**
     * Stable machine key for the task (e.g. 'logs', 'cache', 'import', 'media').
     *
     * @return string
     */
    public function key(): string;

    /**
     * Human-readable label for the Panel card.
     *
     * @return string
     */
    public function label(): string;

    /**
     * One-line description of what the task removes, shown under the label.
     *
     * @return string
     */
    public function description(): string;

    /**
     * Compute — without deleting anything — what this task would reclaim.
     *
     * @param MaintenanceOptions $options shared retention/size options
     * @return MaintenancePreview counts and bytes that would be freed
     */
    public function preview(MaintenanceOptions $options): MaintenancePreview;

    /**
     * Perform one chunk of the task.
     *
     * Blocking tasks ignore $offset/$limit and complete in a single call; chunked tasks
     * process the slice [$offset, $offset + $limit) and report the next offset.
     *
     * @param MaintenanceOptions $options shared retention/size options
     * @param int $offset item offset to resume from (0 for the first/only chunk)
     * @param int $limit maximum items to process this chunk
     * @return MaintenanceRunResult progress and reclaimed bytes for this chunk
     */
    public function run(MaintenanceOptions $options, int $offset = 0, int $limit = 0): MaintenanceRunResult;
}
