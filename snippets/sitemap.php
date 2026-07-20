<?php

use BSBI\WebBase\helpers\KirbyRetrievalException;
use BSBI\WebBase\helpers\SeoPolicy;

?>
<?= '<?xml version="1.0" encoding="utf-8"?>'; ?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php if (!isset($pages)) :
    throw new Exception('No $page supplied');
endif;

if (!isset($ignore)) :
    $ignore = [];
endif;

    foreach ($pages as $p): ?>
        <?php
        // Skip pages excluded by uri or template, members-area pages, and pages
        // explicitly flagged noindex. Matching on the intended template name (not
        // just the uri) keeps nested pages such as order pages at checkout/<uuid>
        // out of the sitemap even though their uri never equals 'order'.
        if (SeoPolicy::isExcludedFromSitemap(
            $p->uri(),
            $p->intendedTemplate()->name(),
            $p->meta_robots()->exists() ? $p->meta_robots()->value() : null,
            $ignore
        )) continue;
        ?>
        <url>
            <loc><?= html($p->url()) ?></loc>

            <?php
            // Use the last modified date for accurate crawling signals
            // 'c' format outputs the date in the required ISO 8601 format
            ?>
            <lastmod><?= $p->modified('c', 'date') ?></lastmod>

            <?php
            // Priority is often ignored by Google, but can be helpful for initial context
            // This calculates priority based on depth (home page = 1, deeper pages = lower)
            $priority = $p->isHomePage() ? 1.0 : number_format(0.5 / $p->depth(), 1);
            ?>
            <priority><?= $priority ?></priority>
        </url>
    <?php endforeach ?>
</urlset>