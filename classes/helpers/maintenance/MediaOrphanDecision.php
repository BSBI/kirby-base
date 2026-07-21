<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers\maintenance;

/**
 * The outcome of applying {@see MediaOrphanPolicy} to one page's hash directories: the
 * subset judged safe to delete. Pure data — no filesystem effects.
 *
 * @package BSBI\WebBase\helpers\maintenance
 */
final readonly class MediaOrphanDecision
{
    /**
     * @param array<int, MediaOrphanTarget> $deletions hash dirs safe to remove
     */
    public function __construct(
        public array $deletions,
    ) {
    }

    /**
     * The hash directory names to delete.
     *
     * @return array<int, string>
     */
    public function names(): array
    {
        return array_map(static fn (MediaOrphanTarget $t): string => $t->name, $this->deletions);
    }
}
