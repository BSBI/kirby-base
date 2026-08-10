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
        }
    ],
];
