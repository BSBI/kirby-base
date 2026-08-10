<?php

/**
 * Search Analytics Panel Section
 *
 * Displays top search terms and keywords from the SQLite-backed search log.
 *
 * A thin wrapper over SearchService — the aggregation logic (case folding,
 * stop-word filtering, date range) lives there once, rather than being
 * duplicated here as a second, separately-maintained implementation.
 */

use BSBI\WebBase\helpers\KirbyInternalHelper;
use BSBI\WebBase\helpers\SearchService;

return [
    'props' => [
        'headline' => function ($headline = 'Search Analytics') {
            return $headline;
        },
        'limit' => function ($limit = 20) {
            return $limit;
        }
    ],
    'computed' => [
        'topTerms' => function () {
            $searchService = new SearchService($this->kirby()->site(), $this->kirby());
            return $searchService->getTopSearchTerms($this->limit);
        },

        'topKeywords' => function () {
            $searchService = new SearchService($this->kirby()->site(), $this->kirby());
            return $searchService->getTopSearchKeywords($this->limit);
        },

        'summary' => function () {
            $searchService = new SearchService($this->kirby()->site(), $this->kirby());
            return $searchService->getSearchAnalyticsSummary();
        },

        // Gates the "Clear search log" button: search queries are potentially
        // personal data, so wiping the whole log is restricted to admins.
        // Same admin check the API route uses, and the same helper
        // MaintenancePanel::isAdmin() uses for its own admin-only actions.
        'canClearSearchLog' => function () {
            return (new KirbyInternalHelper())->doesCurrentUserHaveRole('admin');
        }
    ],
];
