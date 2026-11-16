<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers\maintenance;

/**
 * The result of running one chunk of a maintenance task.
 *
 * Blocking tasks (logs, cache, import) complete in a single call and return
 * {@see $done} = true with {@see $nextOffset} = 0. Chunked tasks (the future media GC)
 * return {@see $done} = false with a {@see $nextOffset} the Panel loop feeds back in.
 *
 * @package BSBI\WebBase\helpers\maintenance
 */
final readonly class MaintenanceRunResult
{
    /**
     * @param bool $done whether the task has finished (no further chunks required)
     * @param int $processed number of items processed in this chunk
     * @param int $reclaimedBytes bytes reclaimed in this chunk
     * @param int $nextOffset offset to pass to the next chunk (0 when done)
     * @param string|null $note where the whole run has got to, in the task's own words
     *        (e.g. "Members page 4 · 800 people · 3 created"). The Panel shows it in
     *        place of the percentage, for a task that can't count its items up front.
     */
    public function __construct(
        public bool $done,
        public int $processed,
        public int $reclaimedBytes,
        public int $nextOffset = 0,
        public ?string $note = null,
    ) {
    }

    /**
     * A completed single-shot result for a blocking task.
     *
     * @param int $processed number of items processed
     * @param int $reclaimedBytes bytes reclaimed
     * @return self
     */
    public static function completed(int $processed, int $reclaimedBytes): self
    {
        return new self(true, $processed, $reclaimedBytes, 0);
    }
}
