<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers\maintenance;

/**
 * The dry-run result of a maintenance task: what *would* be removed, without touching
 * anything. Presented to the admin before any destructive button is armed.
 *
 * @package BSBI\WebBase\helpers\maintenance
 */
final readonly class MaintenancePreview
{
    /**
     * @param int $items number of items (files/dirs/log entries) that would be removed or rewritten
     * @param int $bytes total bytes that would be reclaimed
     * @param array<int, string> $sample human-readable sample lines describing the biggest/first targets
     */
    public function __construct(
        public int $items,
        public int $bytes,
        public array $sample = [],
    ) {
    }

    /**
     * An empty preview — nothing to do.
     *
     * @return self
     */
    public static function empty(): self
    {
        return new self(0, 0, []);
    }
}
