<?php

declare(strict_types=1);

use BSBI\WebBase\forms\FormBuilderOptions;
use Kirby\Cms\App;
use Kirby\Cms\Pages;
use Kirby\Cms\Site;

/**
 * All hand-written form pages that take extra sections (templates mapped in
 * the forms.sectionsFields option), drafts included.
 *
 * Used only by the form_extra_sections content index for rebuilds; day to day
 * the index is kept up to date by page hooks.
 *
 * @param Site $site
 * @param App  $kirby
 * @return Pages
 */
return function (Site $site, App $kirby): Pages {
    $templates = FormBuilderOptions::extraSectionsTemplates($kirby);
    if ($templates === []) {
        return new Pages([]);
    }

    return $site->index(true)->filter(
        fn ($page) => in_array($page->intendedTemplate()->name(), $templates, true)
    );
};
