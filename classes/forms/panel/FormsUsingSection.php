<?php

declare(strict_types=1);

namespace BSBI\WebBase\forms\panel;

use Kirby\Cms\Page;

/**
 * Finds the panel-built forms that use a library section, directly or
 * through a variation of it.
 *
 * Kept behind an interface so key locking can be tested without the
 * content index.
 */
interface FormsUsingSection
{
    /**
     * Returns the form pages that use the section.
     *
     * @param Page $section A library section page
     * @return list<Page>
     */
    public function forms(Page $section): array;
}
