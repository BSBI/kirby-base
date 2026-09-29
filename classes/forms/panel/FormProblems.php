<?php

declare(strict_types=1);

namespace BSBI\WebBase\forms\panel;

/**
 * Collects the problems found while reading a panel-built form, worded for
 * the editor who has to fix them.
 *
 * Reading never fails on bad content: the offending piece is left out and a
 * problem is recorded here, so a live form keeps rendering while an editor
 * sorts it out.
 */
final class FormProblems
{
    /** @var string[] */
    private array $problems = [];

    /**
     * Records a problem, ignoring an exact repeat.
     *
     * @param string $problem Editor-facing description
     */
    public function add(string $problem): void
    {
        if (!in_array($problem, $this->problems, true)) {
            $this->problems[] = $problem;
        }
    }

    /**
     * Returns every problem recorded, in the order found.
     *
     * @return string[]
     */
    public function all(): array
    {
        return $this->problems;
    }

    /**
     * Returns true if nothing has been recorded.
     */
    public function isEmpty(): bool
    {
        return $this->problems === [];
    }
}
