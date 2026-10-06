<?php

declare(strict_types=1);

use Kirby\Cms\App;
use Kirby\Cms\Pages;
use Kirby\Cms\Site;

/**
 * All panel-built form pages (templates in the forms.builderTemplates option,
 * default form_builder), drafts included.
 *
 * Used only by the form_builders content index for rebuilds; day to day the
 * index is kept up to date by page hooks.
 *
 * @param Site $site
 * @param App $kirby
 * @return Pages
 */
return function (Site $site, App $kirby): Pages {
    $templates = $kirby->option('forms.builderTemplates', ['form_builder']);
    $templates = is_array($templates) ? $templates : ['form_builder'];

    return $site->index(true)->filter(
        fn ($page) => in_array($page->intendedTemplate()->name(), $templates, true)
    );
};
