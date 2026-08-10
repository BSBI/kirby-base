<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

use BSBI\WebBase\models\BaseWebPage;
use Closure;
use Kirby\Cms\App;
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
        if (empty(trim($query ?? ''))) {
            return $this->site->index()->limit(0);
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

            $pageIds = $searchIndex->searchAllIds($query, $isMemberOrAdmin, $templates);

            if (empty($pageIds)) {
                return $this->site->index()->limit(0);
            }

            return pages($pageIds)->paginate($perPage);
        } catch (Throwable $e) {
            KirbyBaseHelper::writeToLogFile('content-index', 'SQLite search failed: ' . $e->getMessage());
            // Never fall back to an unfiltered search — return empty results to avoid leaking
            // restricted content types (e.g. form_submission) through the fallback path.
            return $this->site->index()->limit(0);
        }
    }

    // endregion

    // region ANALYTICS

    /** @var string Default search log database path, relative to the `logs` root */
    private const string DEFAULT_LOG_DATABASE_PATH = '/search/search-log.sqlite';

    /**
     * Open the SQLite-backed search log store.
     *
     * Resolves `search.logDatabasePath` the same way SearchIndexHelper resolves
     * `search.databasePath` — relative to a Kirby root, with a default that
     * lands the file alongside the search index (`site/logs/search/`), but in
     * its own file: the index is regenerable from content, the log is not.
     */
    private function store(): SearchLogStore
    {
        $logDatabasePath = (string)option('search.logDatabasePath', self::DEFAULT_LOG_DATABASE_PATH);

        return SearchLogStore::open($this->kirby->root('logs') . $logDatabasePath);
    }

    /**
     * Get top search terms by frequency.
     *
     * @param int $limit Number of results to return
     * @return array<array{term: string, count: int}>
     */
    public function getTopSearchTerms(int $limit = 20): array
    {
        return $this->store()->topTerms($limit);
    }

    /**
     * Get top search keywords by frequency (parsed from queries, stop words removed).
     *
     * Expands the store's per-query counts back into a repeated query list so
     * the existing tokenisation/stop-word logic in
     * {@see SearchTextHelper::extractKeywordCounts()} can be reused unchanged —
     * each search event still contributes its keywords exactly once, matching
     * the weighting the previous per-page-loop version produced.
     *
     * @param int $limit Number of results to return
     * @return array<array{keyword: string, count: int}>
     */
    public function getTopSearchKeywords(int $limit = 20): array
    {
        $queries = [];
        foreach ($this->store()->queryCounts() as $term => $count) {
            for ($i = 0; $i < $count; $i++) {
                $queries[] = $term;
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
        return $this->store()->summary();
    }

    /**
     * Delete every row in the search query log.
     *
     * Used by the "Clear search log" Panel action: the SQLite log file is
     * carried along by full-site restores (e.g. onto staging), and search
     * queries are potentially personal data, so admins need a way to wipe it
     * without shelling in. Resolves the store the same way every other
     * analytics method here does — same `search.logDatabasePath` option,
     * same default — so it always clears the file the Panel is displaying.
     *
     * @return int Number of rows deleted
     */
    public function clearSearchLog(): int
    {
        return $this->store()->deleteAll();
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
