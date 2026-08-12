<?php

declare(strict_types=1);

namespace BSBI\WebBase\models;

use Kirby\Cms\Page;

/**
 * The outcome of a create-or-recover page call.
 *
 * Carries the page together with whether it was freshly created or recovered
 * from a concurrent request's create of the same slug — recovered pages
 * already existed, so callers typically follow up with their update path
 * rather than treating the content as newly written.
 */
final readonly class PageCreationResult
{
    /**
     * @param Page $page The created or recovered page
     * @param bool $recovered True when the page came from recovery rather than this call's create
     */
    public function __construct(
        public Page $page,
        public bool $recovered
    ) {
    }
}
