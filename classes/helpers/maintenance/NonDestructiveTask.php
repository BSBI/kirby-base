<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers\maintenance;

/**
 * A maintenance task that frees nothing — it writes a cache, lists something,
 * rebuilds an index. The Panel shows the task's own summary instead of
 * "Would free …", a neutral button instead of the red bin, and a "Run" confirm
 * that does not warn about deletion (bsbi-web#734).
 *
 * The task's {@see MaintenancePreview} carries the summary for a non-empty
 * preview; {@see self::emptySummary()} is the empty-state line, asked for
 * separately so a deferred card can show it without running the preview.
 */
interface NonDestructiveTask
{
    /**
     * The Panel icon name for the run button, e.g. `refresh` or `search`.
     */
    public function icon(): string;

    /**
     * The line shown instead of "Nothing to reclaim" when the preview counts nothing.
     */
    public function emptySummary(): string;
}
