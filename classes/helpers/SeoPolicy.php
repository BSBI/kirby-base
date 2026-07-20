<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

/**
 * Pure decisions for search-engine visibility: whether a page belongs in
 * sitemap.xml and whether it should carry a robots "noindex" meta tag.
 *
 * The exclusion lists hold page *template* names. Historically the sitemap
 * matched them against the page uri only, which silently let nested pages
 * (e.g. customer order pages at checkout/<uuid>, whose template is `order`)
 * leak into the sitemap and thence into search results. These helpers match
 * on both uri and template so nested pages are excluded correctly.
 */
final class SeoPolicy
{
    /**
     * Decide whether a page should be omitted from sitemap.xml.
     *
     * @param string $uri the page uri (path relative to site root, no leading slash)
     * @param string $template the page's intended template name
     * @param string|null $metaRobots the page's meta_robots field value, if any
     * @param list<string> $ignore uris and/or template names to exclude
     * @return bool true if the page must not appear in the sitemap
     */
    public static function isExcludedFromSitemap(
        string $uri,
        string $template,
        ?string $metaRobots,
        array $ignore
    ): bool {
        if (in_array($uri, $ignore, true)) {
            return true;
        }

        if (in_array($template, $ignore, true)) {
            return true;
        }

        if (str_starts_with($uri, 'members/')) {
            return true;
        }

        return $metaRobots === 'noindex';
    }

    /**
     * Decide whether a page's template means it should emit a robots noindex tag.
     *
     * @param string $template the page's intended template name
     * @param list<string> $noindexTemplates template names that must never be indexed
     * @return bool true if the page should carry <meta name="robots" content="noindex">
     */
    public static function isNoindexTemplate(string $template, array $noindexTemplates): bool
    {
        return in_array($template, $noindexTemplates, true);
    }
}
