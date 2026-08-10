<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

use Kirby\Cms\App;
use Kirby\Cms\Site;

/**
 * Writes search queries to the site's search log as `search_log_item` pages.
 *
 * The logger is the config kill-switch for search logging: when constructed
 * with `enabled = false` (from `option('search.logQueries')`), logging is a
 * no-op, so searches on large sites avoid the cost of creating a content page
 * per query. All Kirby collaborators are injected; no global state is used.
 *
 * Throwables from page creation are deliberately allowed to propagate — the
 * caller decides how logging failures are reported.
 */
final readonly class SearchQueryLogger
{
    /**
     * @param App $kirby The Kirby application, used to impersonate for page creation
     * @param Site $site The site whose `search_log` page receives log entries
     * @param bool $enabled Whether search logging is enabled (the kill-switch)
     */
    public function __construct(
        private App $kirby,
        private Site $site,
        private bool $enabled,
    ) {
    }

    /**
     * Logs a search query as a listed `search_log_item` child of the site's
     * `search_log` page.
     *
     * Returns false without writing anything when logging is disabled or when
     * the site has no `search_log` page.
     *
     * @param string $query The search query to log
     * @return bool True when a log entry was written, false otherwise
     * @throws \Throwable If creating or listing the log entry page fails
     */
    public function log(string $query): bool
    {
        if (!$this->enabled) {
            return false;
        }

        /** @var \Kirby\Cms\Page|null $searchLog first() returns null when no page matches */
        $searchLog = $this->site->children()->template('search_log')->first();

        if ($searchLog === null) {
            return false;
        }

        $this->kirby->impersonate('kirby', function () use ($searchLog, $query) {
            $logItem = $searchLog->createChild([
                'template' => 'search_log_item',
                'slug' => date('Y-m-d H:i:s'),
                'content' => [
                    'title' => $query . ' (' . date('Y-m-d H:i:s') . ')',
                    'searchQuery' => $query,
                    'searchDate' => date('Y-m-d H:i:s')
                ]
            ]);
            return $logItem->changeStatus('listed');
        });

        return true;
    }
}
