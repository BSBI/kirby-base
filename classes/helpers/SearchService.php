<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

use BSBI\WebBase\models\BaseWebPage;
use BSBI\WebBase\models\PaginatedPages;
use Closure;
use Kirby\Cms\App;
use Kirby\Cms\Pages;
use Kirby\Cms\Pagination;
use Kirby\Cms\Site;
use Kirby\Toolkit\Collection;
use Kirby\Toolkit\Str;
use Throwable;

/**
 * Service for search functionality: query collection building, SQLite FTS5 search,
 * search analytics, content type options, and search-term highlighting.
 *
 * The optional $hasSessionCookieFn is injected from KirbyBaseHelper to allow
 * SQLite search to check for an existing session before accessing the user object
 * (avoiding unwanted session creation that would prevent page caching).
 */
final readonly class SearchService
{
    /**
     * @param Site $site The Kirby site object
     * @param App $kirby The Kirby application object
     * @param Closure|null $hasSessionCookieFn Optional: fn(): bool — checks for an active
     *        session cookie without starting a new session. When null, the SQLite search
     *        path assumes no authenticated user.
     */
    public function __construct(
        private Site     $site,
        private App      $kirby,
        private ?Closure $hasSessionCookieFn = null,
    ) {
    }

    // region QUERY INPUT

    /**
     * Get the query string (q) from the current request, sanitised and normalised.
     *
     * @return string
     */
    public function getSearchQuery(): string
    {
        $query = get('q', '');
        if (!is_string($query) || empty($query)) {
            $query = '';
        }
        return self::normaliseQueryPunctuation(strip_tags($query));
    }

    /**
     * Normalise punctuation in a search query so it does not interfere with
     * FTS5 query parsing or tokenisation.
     *
     * Hyphens are replaced with spaces because FTS5 treats `-` as a NOT operator,
     * so "red dead-nettle" would otherwise be parsed as "red dead NOT nettle".
     * Parentheses, commas, slashes, and square brackets are also replaced with
     * spaces since they are not meaningful in user-facing search. Multiple
     * consecutive spaces are collapsed to one.
     *
     * @param string $query Raw query string (HTML already stripped)
     * @return string Normalised query
     */
    public static function normaliseQueryPunctuation(string $query): string
    {
        $query = str_replace(['-', '(', ')', ',', '/', '[', ']'], ' ', $query);
        return trim((string) preg_replace('/\s+/', ' ', $query));
    }

    // endregion

    // region COLLECTION BUILDING

    /**
     * Search the site using Kirby's in-memory search with weighted field scoring.
     *
     * @param string|null $query
     * @param string $params Pipe-separated list of fields to search
     * @param int $perPage
     * @param Collection|null $collection Defaults to site index
     * @return Collection
     */
    public function getSearchCollection(
        ?string     $query = null,
        string      $params = 'title|mainContent|description|keywords',
        int         $perPage = 10,
        ?Collection $collection = null
    ): Collection {
        if ($collection === null) {
            $collection = $this->site->index();
        }

        if (empty(trim($query ?? '')) === true) {
            return $collection->limit(0);
        }

        $params = ['fields' => Str::split($params, '|')];

        $defaults = [
            'fields' => [],
            'score' => []
        ];

        $options = array_merge($defaults, $params);
        $collection = clone $collection;

        $defaultWeights = [
            'id' => 64,
            'title' => 128,
            'description' => 64,
            'keywords' => 64,
            'maincontent' => 32
        ];

        $scores = [];
        $results = $collection->filter(function ($item) use ($query, $options, $defaultWeights, &$scores) {
            $data = $item->content()->toArray();
            $keys = array_keys($data);
            $keys[] = 'id';

            if (empty($options['fields']) === false) {
                $fields = array_map('strtolower', $options['fields']);
                $keys = array_intersect($keys, $fields);
            }

            $fieldValues = [];
            foreach ($keys as $key) {
                $fieldValues[$key] = (string)$item->$key();
            }

            $fieldWeights = array_merge($defaultWeights, $options['score']);
            $scoring = SearchTextHelper::scoreFields($fieldValues, $query, $fieldWeights);

            $scores[$item->id()] = $scoring;
            return $scoring['hits'] > 0;
        });

        return $results->sort(
            fn ($item) => $scores[$item->id()]['score'],
            'desc'
        )->paginate($perPage);
    }

    /**
     * Search using SQLite FTS5 index with automatic fallback to in-memory search.
     *
     * @param string|null $query Search query
     * @param int $perPage Results per page
     * @param string|null $templates Optional comma-delimited template names to filter results
     * @return Collection
     */
    public function getSearchCollectionSqlite(
        ?string $query = null,
        int     $perPage = 10,
        ?string $templates = null
    ): Collection {
        $query = trim($query ?? '');

        if ($query === '') {
            return self::noResults();
        }

        if (!option('search.useSqlite', false)) {
            return $this->getSearchCollection($query, 'title|mainContent|description|keywords', $perPage);
        }

        try {
            $searchIndex = new SearchIndexHelper();
            $hasSession = $this->hasSessionCookieFn !== null && ($this->hasSessionCookieFn)();
            $user = $hasSession ? $this->kirby->user() : null;
            $isMemberOrAdmin = $user &&
                in_array($user->role()->name(), ['member', 'vice_county', 'admin', 'editor']);

            // Count first, then fetch only the page being displayed. Fetching every
            // matching ID and paginating afterwards means Kirby resolves each one into a
            // page object — on a large site a broad query matches thousands, which is
            // enough to exhaust the request's memory to show ten results.
            $total = $searchIndex->countSearchMatches($query, $isMemberOrAdmin, $templates);

            if ($total === 0) {
                return self::noResults();
            }

            $pagination = new Pagination(['limit' => $perPage, 'total' => $total]);
            $pageIds = $searchIndex->searchIds(
                $query,
                $isMemberOrAdmin,
                $templates,
                $pagination->limit(),
                $pagination->offset()
            );

            return PaginatedPages::from(pages($pageIds) ?? new Pages([]), $pagination);
        } catch (Throwable $e) {
            KirbyBaseHelper::writeToLogFile('content-index', 'SQLite search failed: ' . $e->getMessage());
            // Never fall back to an unfiltered search — return empty results to avoid leaking
            // restricted content types (e.g. form_submission) through the fallback path.
            return self::noResults();
        }
    }

    /**
     * An empty result set.
     *
     * Deliberately not `$this->site->index()->limit(0)`: that builds the entire page
     * index — every page on the site — purely to throw it away, so on a large site the
     * cheapest possible outcome became the most expensive one. Any failed or empty
     * search took that path, which made a malformed query a way to exhaust memory.
     *
     * @return Pages
     */
    private static function noResults(): Pages
    {
        return new Pages([]);
    }

    // endregion

    // region ANALYTICS

    /**
     * Get top search terms by frequency.
     *
     * @param int $limit Number of results to return
     * @return array<array{term: string, count: int}>
     */
    public function getTopSearchTerms(int $limit = 20): array
    {
        $searchLog = $this->site->children()->template('search_log')->first();
        if (!$searchLog) {
            return [];
        }

        $logEntries = $searchLog->children()->template('search_log_item');
        $termCounts = [];

        foreach ($logEntries as $entry) {
            $query = strtolower(trim($entry->searchQuery()->value() ?? ''));
            if ($query !== '') {
                $termCounts[$query] = ($termCounts[$query] ?? 0) + 1;
            }
        }

        arsort($termCounts);
        $topTerms = array_slice($termCounts, 0, $limit, true);

        $result = [];
        foreach ($topTerms as $term => $count) {
            $result[] = ['term' => $term, 'count' => $count];
        }

        return $result;
    }

    /**
     * Get top search keywords by frequency (parsed from queries, stop words removed).
     *
     * @param int $limit Number of results to return
     * @return array<array{keyword: string, count: int}>
     */
    public function getTopSearchKeywords(int $limit = 20): array
    {
        $searchLog = $this->site->children()->template('search_log')->first();
        if (!$searchLog) {
            return [];
        }

        $logEntries = $searchLog->children()->template('search_log_item');
        $queries = [];

        foreach ($logEntries as $entry) {
            $query = $entry->searchQuery()->value() ?? '';
            if (trim($query) !== '') {
                $queries[] = $query;
            }
        }

        return SearchTextHelper::extractKeywordCounts($queries, SearchTextHelper::SEARCH_STOP_WORDS, $limit);
    }

    /**
     * Get search analytics summary.
     *
     * @return array{totalSearches: int, uniqueTerms: int, dateRange: array{from: string|null, to: string|null}}
     */
    public function getSearchAnalyticsSummary(): array
    {
        $searchLog = $this->site->children()->template('search_log')->first();
        if (!$searchLog) {
            return [
                'totalSearches' => 0,
                'uniqueTerms' => 0,
                'dateRange' => ['from' => null, 'to' => null]
            ];
        }

        $logEntries = $searchLog->children()->template('search_log_item')->sortBy('searchDate', 'asc');
        $totalSearches = $logEntries->count();
        $uniqueTerms = [];
        $firstDate = null;
        $lastDate = null;

        foreach ($logEntries as $entry) {
            $query = strtolower(trim($entry->searchQuery()->value() ?? ''));
            if ($query !== '') {
                $uniqueTerms[$query] = true;
            }
            $date = $entry->searchDate()->value();
            if ($firstDate === null) {
                $firstDate = $date;
            }
            $lastDate = $date;
        }

        return [
            'totalSearches' => $totalSearches,
            'uniqueTerms' => count($uniqueTerms),
            'dateRange' => ['from' => $firstDate, 'to' => $lastDate]
        ];
    }

    // endregion

    // region OPTIONS

    /**
     * Get the available content type filter options for search.
     *
     * Returns the keys from the search.contentTypeOptions config as an array for use in a select box.
     *
     * @return array
     */
    public function getSearchContentTypeOptions(): array
    {
        $contentTypeOptionsFromConfig = option('search.contentTypeOptions', []);

        $options = [];
        $options[] = ['value' => '', 'display' => 'All content'];
        foreach ($contentTypeOptionsFromConfig as $key => $value) {
            $options[] = ['value' => $value, 'display' => $key];
        }
        return $options;
    }

    // endregion

    // region HIGHLIGHTING

    /**
     * Apply search-term highlighting to all text blocks in a page model.
     *
     * @param BaseWebPage $page
     * @param string $query
     * @return BaseWebPage
     */
    public function highlightSearchQuery(BaseWebPage $page, string $query): BaseWebPage
    {
        $mainContentBlocks = $page->getMainContent();
        foreach ($mainContentBlocks->getListItems() as $block) {
            if (in_array($block->getBlockType(), ['text', 'heading', 'list', 'note'])) {
                $highlightedContent = SearchTextHelper::highlightTerm($block->getBlockContent(), $query);
                $block->setBlockContent($highlightedContent);
            }
        }
        return $page;
    }

    // endregion
}
