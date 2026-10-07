<?php

declare(strict_types=1);

namespace BSBI\WebBase\forms\panel;

use Kirby\Cms\Page;

/**
 * Tells which question keys a form's stored responses use.
 *
 * Kept behind an interface so key locking can be tested without stored
 * submission pages.
 */
interface ResponseKeySource
{
    /**
     * Returns true if the form has any stored responses.
     *
     * @param Page $form A panel-built form page
     */
    public function hasResponses(Page $form): bool;

    /**
     * Returns those of the given keys that at least one stored response uses,
     * in the order given.
     *
     * @param Page     $form A panel-built form page
     * @param string[] $keys Question keys to look for
     * @return list<string>
     */
    public function usedKeys(Page $form, array $keys): array;
}
