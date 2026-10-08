<?php

declare(strict_types=1);

namespace BSBI\WebBase\forms;

/**
 * Named values kept for the visitor's session: where FormSubmissionWriter
 * remembers the responses it saved, so a resubmission can update them.
 */
interface SessionSlots
{
    /**
     * Returns the value stored under the name, or null if there is none.
     */
    public function get(string $name): ?string;

    /**
     * Stores the value under the name for the rest of the session.
     */
    public function set(string $name, string $value): void;
}
