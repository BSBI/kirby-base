<?php

declare(strict_types=1);

namespace BSBI\WebBase\forms\panel;

use Kirby\Cms\Page;

/**
 * Finds the page a stored section reference points at.
 *
 * A reference is what a Kirby pages field stores: a `page://` UUID or a page id.
 * Kept behind an interface so the panel-form readers can be tested without a
 * site tree.
 */
interface SectionPageResolver
{
    /**
     * Returns the referenced page, or null if it cannot be found.
     *
     * @param string $reference A `page://` UUID or page id
     */
    public function resolve(string $reference): ?Page;
}
