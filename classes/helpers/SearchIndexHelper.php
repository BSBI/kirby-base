<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

use Exception;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Exception\InvalidArgumentException;
use Kirby\Filesystem\Dir;
use Kirby\Filesystem\F;
use PDO;
use Throwable;
use WeakMap;

/**
 * SQLite FTS5-based search index manager
 *
 * Handles indexing, querying, and maintenance of the full-text search database.
 *
 * Configuration options (set in config.php under 'search' key):
 * - databasePath: Path to SQLite database relative to site root (default: '/logs/search/search.sqlite')
 * - excludedTemplates: Array of template names to exclude from indexing (default: ['error', 'error-500', 'login'])
 * - excludedPaths: Array of page path prefixes to exclude from indexing (default: [])
 * - membersPathPrefix: Path prefix that identifies members-only content (default: 'members')
 * - stopWords: Array of stop words to filter from search queries (has sensible defaults)
 * - fieldWeights: Array mapping field names to BM25 weights (has sensible defaults)
 * - exactMatchBoost: Boost value for exact matches on title/vernacular_name (default: 100)
 * - exactMatchFields: Array of field names that receive exact match boost (default: ['vernacular_name', 'title'])
 * - keyPhraseBoost: Boost value when a search query exactly matches a key_search_phrases entry (default: 100000)
 */
class SearchIndexHelper
{
    /** @var string Default database path relative to site root */
    private const string DEFAULT_DATABASE_PATH = '/logs/search/search.sqlite';

    /** @var array<string> Default stop words for query filtering */
    private const array DEFAULT_STOP_WORDS = [
        'a', 'an', 'the', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for',
        'of', 'with', 'by', 'from', 'is', 'are', 'was', 'were', 'be', 'been',
        'being', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would',
        'could', 'should', 'may', 'might', 'must', 'shall', 'can', 'need',
        'this', 'that', 'these', 'those', 'it', 'its'
    ];

    /** @var array<string> Default templates to exclude from the search index */
    private const array DEFAULT_EXCLUDED_TEMPLATES = [
        'error', 'error-500', 'login', 'search_log', 'search_log_item', 'file_archive', 'form_submission', 'image_bank'
    ];

    /** @var array<string> Templates to exclude from panel search (all_pages table) */
    private const array PANEL_EXCLUDED_TEMPLATES = [
        'search_log', 'search_log_item'
    ];

    /** @var array<string, float> Default BM25 field weights */
    private const array DEFAULT_FIELD_WEIGHTS = [
        'page_id' => 0.0,
        'title' => 10.0,
        'vernacular_name' => 20.0,
        'description' => 5.0,
        'keywords' => 5.0,
        'main_content' => 1.0,
        'additional_content' => 5.0,
        'key_search_phrases' => 10000.0,
        'url' => 0.0,
        'is_members_only' => 0.0,
        'template' => 0.0,
    ];

    /** @var array<string> Default fields that receive exact match boost */
    private const array DEFAULT_EXACT_MATCH_FIELDS = ['vernacular_name', 'title'];

    /** @var int Default boost value for exact matches */
    private const int DEFAULT_EXACT_MATCH_BOOST = 100;

    /** @var int Default boost value when a search query exactly matches a key search phrase */
    private const int DEFAULT_KEY_PHRASE_BOOST = 100000;

    /** @var int Current schema version — increment when FTS5 columns change */
    private const int CURRENT_SCHEMA_VERSION = 2;

    /** @var string Default path prefix for members-only content */
    private const string DEFAULT_MEMBERS_PATH_PREFIX = 'members';

    protected PDO $database;
    protected App $kirby;

    /** @var PDO|null Cached static database connection for fast lookups */
    private static ?PDO $staticDb = null;

    /**
     * Open connections, keyed by database file.
     *
     * The delete hook constructs one of these per page, so without this a bulk
     * operation opened a fresh SQLite connection for every page it touched —
     * along with a schema-version query and two table-existence checks. Keyed by
     * file rather than held as a single connection because the path is
     * overridable, and a subclass or test pointing elsewhere must not silently
     * receive somebody else's database.
     *
     * @var array<string, PDO>
     */
    private static array $connections = [];

    /**
     * Connections whose one-off table migrations have already run this request.
     *
     * Keyed by the PDO object rather than by table name: two databases must not
     * share a memo, or a second file would skip a migration it never had. A
     * WeakMap rather than an spl_object_id() array because PHP hands the freed id
     * straight to the next object — a subclass supplying its own connection would
     * inherit the memo of one that had already gone, and skip a migration on a
     * database that never had it.
     *
     * @var WeakMap<PDO, true>|null
     */
    private static ?WeakMap $migrated = null;

    /**
     * Constructor - initializes database connection
     *
     * @throws Exception If database cannot be initialized
     */
    public function __construct()
    {
        $this->kirby = kirby();
        $this->initializeDatabase();
    }

    /**
     * Get the database path from configuration
     *
     * @return string Database path relative to site root
     */
    private function getDatabasePath(): string
    {
        return option('search.databasePath', self::DEFAULT_DATABASE_PATH);
    }

    /**
     * Get the list of excluded templates from configuration
     *
     * Merges default exclusions (required by kirby-base) with any site-specific exclusions.
     *
     * @return array<string> Template names to exclude from indexing
     */
    private function getExcludedTemplates(): array
    {
        $siteExclusions = option('search.excludedTemplates', []);
        return array_unique(array_merge(self::DEFAULT_EXCLUDED_TEMPLATES, $siteExclusions));
    }

    /**
     * Get the list of excluded path prefixes from configuration
     *
     * @return array<string> Path prefixes to exclude from indexing
     */
    private function getExcludedPaths(): array
    {
        return option('search.excludedPaths', []);
    }

    /**
     * Get the members path prefix from configuration
     *
     * @return string Path prefix that identifies members-only content
     */
    private function getMembersPathPrefix(): string
    {
        return option('search.membersPathPrefix', self::DEFAULT_MEMBERS_PATH_PREFIX);
    }

    /**
     * Get stop words from configuration
     *
     * @return array<string> Stop words to filter from search queries
     */
    protected function getStopWords(): array
    {
        return option('search.stopWords', self::DEFAULT_STOP_WORDS);
    }

    /**
     * Get field weights from configuration
     *
     * @return array<string, float> Field name to weight mapping
     */
    protected function getFieldWeights(): array
    {
        return option('search.fieldWeights', self::DEFAULT_FIELD_WEIGHTS);
    }

    /**
     * Get exact match boost value from configuration
     *
     * @return int Boost value for exact matches (subtracted from BM25 score)
     */
    protected function getExactMatchBoost(): int
    {
        return option('search.exactMatchBoost', self::DEFAULT_EXACT_MATCH_BOOST);
    }

    /**
     * Get fields that receive exact match boost from configuration
     *
     * @return array<string> Field names that receive exact match boost
     */
    protected function getExactMatchFields(): array
    {
        return option('search.exactMatchFields', self::DEFAULT_EXACT_MATCH_FIELDS);
    }

    /**
     * Build the BM25 function call string with configured weights
     *
     * @return string BM25 function call for SQL query
     */
    private function buildBm25Call(): string
    {
        $weights = $this->getFieldWeights();

        // Weights must be in schema column order
        $orderedWeights = [
            $weights['page_id'] ?? 0.0,
            $weights['title'] ?? 10.0,
            $weights['vernacular_name'] ?? 20.0,
            $weights['description'] ?? 5.0,
            $weights['keywords'] ?? 5.0,
            $weights['main_content'] ?? 1.0,
            $weights['additional_content'] ?? 5.0,
            $weights['key_search_phrases'] ?? 10000.0,
            $weights['url'] ?? 0.0,
            $weights['is_members_only'] ?? 0.0,
            $weights['template'] ?? 0.0,
        ];

        return 'bm25(search_index, ' . implode(', ', $orderedWeights) . ')';
    }

    /**
     * Build the exact match boost SQL clause
     *
     * Generates CASE expressions that subtract a boost value from the BM25 score
     * (making it more negative = higher ranked) when:
     * - the query exactly matches a configured field (title, vernacular_name), or
     * - the query exactly matches any pipe-delimited phrase in key_search_phrases.
     *
     * @return string SQL CASE statements for exact match boosting, or empty string
     */
    private function buildExactMatchClause(): string
    {
        $clauses = [];

        $fields = $this->getExactMatchFields();
        $boost = $this->getExactMatchBoost();
        if (!empty($fields) && $boost > 0) {
            foreach ($fields as $field) {
                $clauses[] = "CASE WHEN LOWER($field) = LOWER(:exact_query) THEN $boost ELSE 0 END";
            }
        }

        $keyPhraseBoost = $this->getKeyPhraseBoost();
        if ($keyPhraseBoost > 0) {
            $clauses[] = "CASE WHEN (key_search_phrases != '' AND "
                . "('|' || LOWER(key_search_phrases) || '|') LIKE ('%|' || LOWER(:exact_query) || '|%')) "
                . "THEN $keyPhraseBoost ELSE 0 END";
        }

        if (empty($clauses)) {
            return '';
        }

        return ' - ' . implode(' - ', $clauses);
    }

    /**
     * Extract index data from a page for insertion into the search index
     *
     * @param Page $page The page to extract data from
     * @param string $membersPrefix The members path prefix for detecting members-only content
     * @return array<string, string> Associative array of field values for the search index
     * @throws InvalidArgumentException
     */
    private function getPageIndexData(Page $page, string $membersPrefix): array
    {
        $content = $page->content();
        $isMembersOnly = (!empty($membersPrefix) && str_starts_with($page->id(), $membersPrefix)) ? '1' : '0';
        $mainContent = strip_tags((string)($content->mainContent()->value() ?? ''));
        $additionalContent = $this->getAdditionalFieldsContent($page);

        return [
            'page_id' => $page->id(),
            'title' => (string)($content->title()->value() ?? ''),
            'vernacular_name' => (string)($content->vernacularName()->value() ?? ''),
            'description' => (string)($content->description()->value() ?? ''),
            'keywords' => (string)($content->keywords()->value() ?? ''),
            'main_content' => $mainContent,
            'additional_content' => $additionalContent,
            'key_search_phrases' => self::normaliseKeySearchPhrases(
                (string)($content->get('key_search_phrases')->value() ?? '')
            ),
            'url' => $page->url(),
            'is_members_only' => $isMembersOnly,
            'template' => $page->template()->name(),
            'last_updated' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Initialize database connection, creating file and schema if needed
     *
     * After opening the connection, migrates the schema to the current version
     * if an older database file already exists.
     *
     * @throws Exception If database directory cannot be created or database connection fails
     */
    private function initializeDatabase(): void
    {
        $file = $this->kirby->root('site') . $this->getDatabasePath();

        if (F::exists($file) === false) {
            $dir = dirname($file);
            if (is_dir($dir) === false) {
                if (Dir::make($dir) === false) {
                    throw new Exception("Failed to create search database directory: $dir");
                }
            }
            $this->createDatabase($file);

            // Any handle cached for this path belongs to the file that has just
            // gone. SQLite keeps writing to an unlinked inode quite happily, so
            // reusing it would put writes somewhere nothing can read back.
            unset(self::$connections[$file]);
        }

        if (isset(self::$connections[$file])) {
            // Already open, and its schema already checked this request.
            $this->database = self::$connections[$file];

            return;
        }

        $this->database = new PDO('sqlite:' . $file);
        $this->database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->ensureCurrentSchema();

        self::$connections[$file] = $this->database;
    }

    /**
     * Forget any open connections.
     *
     * For tests, which must not inherit a connection to a temporary database
     * that another test has since removed.
     */
    public static function resetConnections(): void
    {
        self::$connections = [];
        self::$migrated = null;
        self::$staticDb = null;
    }

    /**
     * The migration memo, created on first use.
     *
     * A static property cannot be initialised with an object, so it is built lazily.
     *
     * @return WeakMap<PDO, true> the connections already migrated
     */
    private static function migrated(): WeakMap
    {
        return self::$migrated ??= new WeakMap();
    }

    /**
     * Ensure the database schema is at the current version, migrating if needed
     *
     * Reads the schema_version key from search_meta. If it is absent or below
     * CURRENT_SCHEMA_VERSION the search_index FTS5 table is dropped and recreated
     * with the current column set. The index will be empty until a full rebuild
     * is triggered from the Panel.
     */
    private function ensureCurrentSchema(): void
    {
        // Guard against databases that pre-date the search_meta table
        try {
            $result = $this->database->query(
                "SELECT value FROM search_meta WHERE key = 'schema_version' LIMIT 1"
            );

            if ($result !== false) {
                $row = $result->fetch(PDO::FETCH_ASSOC);
                $stored = $row !== false ? (int)$row['value'] : 0;
            } else {
                $stored = 0;
            }
        } catch (Throwable $e) {
            $stored = 0;
        }

        if ($stored < self::CURRENT_SCHEMA_VERSION) {
            try {
                $this->migrateToCurrentSchema();
            } catch (Throwable $e) {
                // Migration failure (e.g. read-only DB file) — log and continue with the
                // existing schema rather than propagating an exception that would cause
                // SearchService to fall back to an unfiltered search.
                KirbyBaseHelper::writeToLogFile('content-index', 'Search schema migration failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Drop and recreate the search_index FTS5 table with the current schema
     *
     * Called when the stored schema version is older than CURRENT_SCHEMA_VERSION.
     * The table is left empty — a full rebuild must be triggered afterwards.
     */
    private function migrateToCurrentSchema(): void
    {
        $this->database->exec('DROP TABLE IF EXISTS search_index');
        $this->database->exec('
            CREATE VIRTUAL TABLE search_index USING fts5(
                page_id,
                title,
                vernacular_name,
                description,
                keywords,
                main_content,
                additional_content,
                key_search_phrases,
                url,
                is_members_only,
                template,
                last_updated UNINDEXED,
                tokenize="porter unicode61"
            )
        ');

        // search_meta may not exist in databases created before it was introduced
        $this->database->exec(
            'CREATE TABLE IF NOT EXISTS search_meta (key TEXT PRIMARY KEY, value TEXT)'
        );

        $stmt = $this->database->prepare(
            'INSERT OR REPLACE INTO search_meta (key, value) VALUES (:key, :value)'
        );
        $stmt->execute(['key' => 'schema_version', 'value' => (string)self::CURRENT_SCHEMA_VERSION]);
    }

    /**
     * Get the key phrase boost value from configuration
     *
     * @return int Boost value subtracted from BM25 score when a key phrase matches exactly
     */
    protected function getKeyPhraseBoost(): int
    {
        return option('search.keyPhraseBoost', self::DEFAULT_KEY_PHRASE_BOOST);
    }

    /**
     * Create the SQLite database with FTS5 schema
     *
     * @param string $file Path to database file
     */
    private function createDatabase(string $file): void
    {
        $pdo = new PDO('sqlite:' . $file);
        $pdo->exec('
            CREATE VIRTUAL TABLE search_index USING fts5(
                page_id,
                title,
                vernacular_name,
                description,
                keywords,
                main_content,
                additional_content,
                key_search_phrases,
                url,
                is_members_only,
                template,
                last_updated UNINDEXED,
                tokenize="porter unicode61"
            );

            CREATE TABLE search_meta (
                key TEXT PRIMARY KEY,
                value TEXT
            );

            CREATE TABLE page_lookup (
                ddb_id TEXT PRIMARY KEY,
                page_id TEXT NOT NULL
            );
            CREATE INDEX idx_page_lookup_page_id ON page_lookup(page_id);

            CREATE TABLE all_pages (
                page_id TEXT PRIMARY KEY,
                title TEXT NOT NULL DEFAULT ""
            );
        ');
    }

    /**
     * Normalise a raw key_search_phrases field value for storage
     *
     * Splits the input on newlines (and optional carriage returns), trims each
     * phrase, discards blank lines, and joins the results with '|' delimiters so
     * that the exact-match LIKE clause can safely find individual phrases.
     *
     * Example: "rare plant register\nRare Plant Registers" → "rare plant register|Rare Plant Registers"
     *
     * @param string $raw Raw textarea value from the Kirby content field
     * @return string Pipe-delimited, normalised phrase string (empty string if no phrases)
     */
    public static function normaliseKeySearchPhrases(string $raw): string
    {
        if (trim($raw) === '') {
            return '';
        }

        $phrases = array_filter(
            array_map('trim', preg_split('/\r?\n/', $raw) ?: []),
            fn(string $p): bool => $p !== ''
        );

        return implode('|', $phrases);
    }

    /**
     * Get additional field values for a page based on its template
     *
     * @param Page $page The page to get additional fields for
     * @return string Combined additional field values
     * @throws InvalidArgumentException If page content cannot be retrieved
     */
    private function getAdditionalFieldsContent(Page $page): string
    {
        $additionalFieldsConfig = option('search.additionalFields', []);
        $templateName = $page->template()->name();

        if (!isset($additionalFieldsConfig[$templateName])) {
            return '';
        }

        $fieldNames = explode(',', (string) $additionalFieldsConfig[$templateName]);
        $content = $page->content();
        $values = [];

        foreach ($fieldNames as $fieldName) {
            $fieldName = trim($fieldName);
            if (!empty($fieldName)) {
                $value = (string)($content->$fieldName()->value() ?? '');
                if (!empty($value)) {
                    $values[] = strip_tags($value);
                }
            }
        }

        return implode(' ', $values);
    }

    /**
     * Index a single page in the search database
     *
     * @param Page $page The Kirby page to index
     * @return bool True if page was indexed, false if skipped
     * @throws InvalidArgumentException If page content cannot be retrieved
     */
    public function indexPage(Page $page): bool
    {
        // Remove existing entry first
        $this->removePage($page->id());

        // Always update all_pages table regardless of search index eligibility
        $this->indexAllPagesEntry($page);

        // Check if page should be indexed in the search index
        if (!$this->shouldIndex($page)) {
            return false;
        }

        $membersPrefix = $this->getMembersPathPrefix();
        $indexData = $this->getPageIndexData($page, $membersPrefix);

        $stmt = $this->database->prepare('
            INSERT INTO search_index (page_id, title, vernacular_name, description, keywords, main_content, additional_content, key_search_phrases, url, is_members_only, template, last_updated)
            VALUES (:page_id, :title, :vernacular_name, :description, :keywords, :main_content, :additional_content, :key_search_phrases, :url, :is_members_only, :template, :last_updated)
        ');

        $result = $stmt->execute($indexData);

        // Add ddbId to page_lookup if present
        $ddbId = (string)($page->content()->ddbId()->value() ?? '');
        if (!empty($ddbId)) {
            $this->addPageLookup($ddbId, $page->id());
        }

        return $result;
    }

    /**
     * Remove a page from the search index
     *
     * @param string $pageId The Kirby page ID to remove
     * @return bool True if deletion was executed
     */
    public function removePage(string $pageId): bool
    {
        $stmt = $this->database->prepare('DELETE FROM search_index WHERE page_id = :page_id');
        $result = $stmt->execute(['page_id' => $pageId]);

        // Also remove from page_lookup. The ensure call matters on databases
        // created before page_lookup existed — established installs, in other
        // words, which is the case that actually occurs. Without it this
        // statement threw on every single page deletion, and because the throw
        // landed *between* here and the correctly-guarded removeAllPagesEntry()
        // below, that cleanup never ran either.
        $this->ensurePageLookupTable();
        $lookupStmt = $this->database->prepare('DELETE FROM page_lookup WHERE page_id = :page_id');
        $lookupStmt->execute(['page_id' => $pageId]);

        // Also remove from all_pages
        $this->removeAllPagesEntry($pageId);

        return $result;
    }

    /**
     * Add or update a page lookup entry by ddb_id
     *
     * @param string $ddbId The DDB identifier
     * @param string $pageId The Kirby page ID
     * @return bool True if entry was added/updated
     */
    public function addPageLookup(string $ddbId, string $pageId): bool
    {
        if (empty($ddbId)) {
            return false;
        }
        $this->ensurePageLookupTable();
        $stmt = $this->database->prepare('INSERT OR REPLACE INTO page_lookup (ddb_id, page_id) VALUES (:ddb_id, :page_id)');
        return $stmt->execute(['ddb_id' => $ddbId, 'page_id' => $pageId]);
    }

    /**
     * Find a page ID by its ddb_id
     *
     * @param string $ddbId The DDB identifier to look up
     * @return string|null The page ID if found, null otherwise
     */
    public function findPageIdByDdbId(string $ddbId): ?string
    {
        $this->ensurePageLookupTable();
        $stmt = $this->database->prepare('SELECT page_id FROM page_lookup WHERE ddb_id = :ddb_id LIMIT 1');
        $stmt->execute(['ddb_id' => $ddbId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['page_id'] : null;
    }

    /**
     * Ensure the page_lookup table exists (for databases created before this feature)
     */
    private function ensurePageLookupTable(): void
    {
        $migrated = self::migrated();
        if (isset($migrated[$this->database])) {
            return;
        }

        $result = $this->database->query("SELECT name FROM sqlite_master WHERE type='table' AND name='page_lookup'");
        if ($result->fetch() === false) {
            $this->database->exec('
                CREATE TABLE page_lookup (
                    ddb_id TEXT PRIMARY KEY,
                    page_id TEXT NOT NULL
                );
                CREATE INDEX idx_page_lookup_page_id ON page_lookup(page_id);
            ');
        }

        // Recorded only once the table is genuinely there. Set before the attempt,
        // a single transient failure — a locked or read-only database — would
        // disable the guard for the rest of the request, and every later deletion
        // would throw at the statement this exists to protect.
        $migrated[$this->database] = true;
    }

    /**
     * Fast static lookup of page ID by ddb_id
     *
     * Uses a cached database connection for minimal overhead.
     * Call this instead of instantiating SearchIndexHelper for simple lookups.
     *
     * @param string $ddbId The DDB identifier to look up
     * @return string|null The page ID if found, null otherwise
     */
    public static function lookupPageIdByDdbId(string $ddbId): ?string
    {
        if (self::$staticDb === null) {
            $databasePath = option('search.databasePath', self::DEFAULT_DATABASE_PATH);
            $file = kirby()->root('site') . $databasePath;
            if (!F::exists($file)) {
                return null;
            }
            self::$staticDb = new PDO('sqlite:' . $file);
            self::$staticDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }

        try {
            $stmt = self::$staticDb->prepare('SELECT page_id FROM page_lookup WHERE ddb_id = :ddb_id LIMIT 1');
            $stmt->execute(['ddb_id' => $ddbId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ? $result['page_id'] : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Batch lookup of page IDs from a list of DDb identifiers.
     *
     * Returns a map of ddb_id => page_id for all IDs that are found.
     * DDb IDs with no matching entry are omitted from the result.
     * Uses the same cached connection as lookupPageIdByDdbId.
     *
     * @param string[] $ddbIds The DDb identifiers to look up
     * @return array<string, string> Map of ddb_id => page_id
     */
    public static function lookupPageIdsByDdbIds(array $ddbIds): array
    {
        if (empty($ddbIds)) {
            return [];
        }

        if (self::$staticDb === null) {
            $databasePath = option('search.databasePath', self::DEFAULT_DATABASE_PATH);
            $file = kirby()->root('site') . $databasePath;
            if (!F::exists($file)) {
                return [];
            }
            self::$staticDb = new PDO('sqlite:' . $file);
            self::$staticDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }

        try {
            $placeholders = implode(', ', array_fill(0, count($ddbIds), '?'));
            $stmt = self::$staticDb->prepare(
                "SELECT ddb_id, page_id FROM page_lookup WHERE ddb_id IN ($placeholders)"
            );
            $stmt->execute(array_values($ddbIds));
            $result = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $result[(string) $row['ddb_id']] = (string) $row['page_id'];
            }
            return $result;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Find a page ID by exact title match
     *
     * @param string $title The title to search for (case-insensitive, trimmed)
     * @param array<string>|null $templates Optional array of template names to filter by
     * @return string|null The page ID if found, null otherwise
     */
    public function findPageIdByTitle(string $title, ?array $templates = null): ?string
    {
        $title = trim(strtolower($title));
        if (empty($title)) {
            return null;
        }

        $sql = 'SELECT page_id FROM search_index WHERE LOWER(TRIM(title)) = :title';
        $params = ['title' => $title];

        if ($templates !== null && count($templates) > 0) {
            $placeholders = [];
            foreach ($templates as $i => $template) {
                $placeholder = ':template' . $i;
                $placeholders[] = $placeholder;
                $params[$placeholder] = $template;
            }
            $sql .= ' AND template IN (' . implode(', ', $placeholders) . ')';
        }

        $sql .= ' LIMIT 1';

        $stmt = $this->database->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ? $result['page_id'] : null;
    }

    /**
     * Search the index for matching pages
     *
     * @param string $query Search query string
     * @param bool $includeMembersContent Whether to include members-only content
     * @param int $limit Maximum number of results
     * @param int $offset Offset for pagination
     * @param array|null $templates Optional array of template names to filter results
     * @return array{results: array, total: int} Search results with total count
     */
    public function search(string $query, bool $includeMembersContent = false, int $limit = 10, int $offset = 0, ?array $templates = null): array
    {
        $ftsQuery = $this->prepareQuery($query);

        if (empty($ftsQuery)) {
            return ['results' => [], 'total' => 0];
        }

        $membersClause = $includeMembersContent ? '' : "AND is_members_only = '0'";

        // Build template filter clause if templates specified
        $templateClause = '';
        $templateParams = [];
        if ($templates !== null && count($templates) > 0) {
            $placeholders = [];
            foreach ($templates as $i => $template) {
                $placeholder = ':template' . $i;
                $placeholders[] = $placeholder;
                $templateParams[$placeholder] = $template;
            }
            $templateClause = 'AND template IN (' . implode(', ', $placeholders) . ')';
        }

        // Get total count
        $countSql = "
            SELECT COUNT(*) as total
            FROM search_index
            WHERE search_index MATCH :query $membersClause $templateClause
        ";
        $countStmt = $this->database->prepare($countSql);
        $countStmt->bindValue(':query', $ftsQuery, PDO::PARAM_STR);
        foreach ($templateParams as $placeholder => $value) {
            $countStmt->bindValue($placeholder, $value, PDO::PARAM_STR);
        }
        $countStmt->execute();
        $countResult = $countStmt->fetch(PDO::FETCH_ASSOC);
        $total = (int)($countResult['total'] ?? 0);

        // Get results with BM25 ranking and exact-match boost
        $bm25Call = $this->buildBm25Call();
        $exactMatchClause = $this->buildExactMatchClause();
        $resultsSql = "
            SELECT
                page_id,
                title,
                description,
                url,
                template,
                {$bm25Call}{$exactMatchClause}
                as score
            FROM search_index
            WHERE search_index MATCH :query $membersClause $templateClause
            ORDER BY score
            LIMIT :limit OFFSET :offset
        ";
        $resultsStmt = $this->database->prepare($resultsSql);
        $resultsStmt->bindValue(':query', $ftsQuery, PDO::PARAM_STR);
        $resultsStmt->bindValue(':exact_query', trim($query), PDO::PARAM_STR);
        $resultsStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $resultsStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        foreach ($templateParams as $placeholder => $value) {
            $resultsStmt->bindValue($placeholder, $value, PDO::PARAM_STR);
        }
        $resultsStmt->execute();
        $results = $resultsStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'results' => $results,
            'total' => $total
        ];
    }

    /**
     * Search and return all matching page IDs sorted by relevance
     *
     * Returns all matching page IDs without pagination, suitable for use with
     * Kirby's built-in pagination system.
     *
     * @param string $query Search query string
     * @param bool $includeMembersContent Whether to include members-only content
     * @param string|null $templates Optional array of template names to filter results
     * @return array Array of page IDs sorted by relevance
     */
    public function searchAllIds(string $query, bool $includeMembersContent = false, ?string $templates = null): array
    {
        $ftsQuery = $this->prepareQuery($query);

        if (empty($ftsQuery)) {
            return [];
        }

        $membersClause = $includeMembersContent ? '' : "AND is_members_only = '0'";

        // Build template filter clause if templates specified
        $templateClause = '';
        $templateParams = [];
        $templatesAsArray = $templates ? explode(',', $templates) : [];
        if (count($templatesAsArray) > 0) {
            $placeholders = [];
            foreach ($templatesAsArray as $i => $template) {
                $placeholder = ':template' . $i;
                $placeholders[] = $placeholder;
                $templateParams[$placeholder] = $template;
            }
            $templateClause = 'AND template IN (' . implode(', ', $placeholders) . ')';
        }

        // Get all page IDs sorted by BM25 relevance with exact-match boost
        $bm25Call = $this->buildBm25Call();
        $exactMatchClause = $this->buildExactMatchClause();
        $sql = "
            SELECT page_id
            FROM search_index
            WHERE search_index MATCH :query $membersClause $templateClause
            ORDER BY
                {$bm25Call}{$exactMatchClause}
        ";

        $stmt = $this->database->prepare($sql);
        $stmt->bindValue(':query', $ftsQuery, PDO::PARAM_STR);
        $stmt->bindValue(':exact_query', trim($query), PDO::PARAM_STR);
        foreach ($templateParams as $placeholder => $value) {
            $stmt->bindValue($placeholder, $value, PDO::PARAM_STR);
        }
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    }

    /**
     * Prepare query string for FTS5 matching
     *
     * Handles quoted phrases and filters stop words.
     *
     * @param string $query Raw search query
     * @return string FTS5-compatible query string
     */
    private function prepareQuery(string $query): string
    {
        $query = trim($query);
        if (empty($query)) {
            return '';
        }

        // Extract quoted phrases
        preg_match_all('/"([^"]+)"/', $query, $phraseMatches);
        $phrases = $phraseMatches[0] ?? [];

        // Get remaining words after removing quoted phrases
        $remaining = preg_replace('/"[^"]+"/', '', $query);
        $words = preg_split('/\s+/', trim($remaining ?? ''));

        if ($words === false) {
            $words = [];
        }

        // Filter stop words and empty strings
        $stopWords = $this->getStopWords();
        $filteredWords = array_filter($words, fn($word) =>
            strlen((string) $word) > 2 && !in_array(strtolower((string) $word), $stopWords)
        );

        // Build FTS5 query with prefix matching for individual words
        $parts = array_merge($phrases, array_map(fn($w) => $w . '*', $filteredWords));

        return implode(' ', $parts);
    }

    /**
     * Check if a page should be indexed
     *
     * @param Page $page The page to check
     * @return bool True if page should be indexed
     */
    private function shouldIndex(Page $page): bool
    {
        // Exclude certain templates
        if (in_array($page->template()->name(), $this->getExcludedTemplates())) {
            return false;
        }

        // Exclude configured path prefixes
        $pageId = $page->id();
        foreach ($this->getExcludedPaths() as $excludedPath) {
            if (str_starts_with($pageId, $excludedPath)) {
                return false;
            }
        }

        // Only index published/listed pages
        if (!$page->isListed() && !$page->isUnlisted()) {
            return false;
        }

        return true;
    }

    /**
     * Rebuild the entire search index using the allPages collection
     *
     * @return array{search_index: int, all_pages: int} Number of pages indexed in each table
     * @throws Throwable If database transaction fails
     */
    public function rebuildIndex(): array
    {
        // Use transaction for much better performance
        $this->database->beginTransaction();

        try {
            // Clear existing index and page_lookup table
            $this->database->exec('DELETE FROM search_index');
            $this->ensurePageLookupTable();
            $this->database->exec('DELETE FROM page_lookup');

            $count = 0;

            // Use allPages collection (much smaller than site->index())
            // Get both public and members pages by temporarily impersonating admin
            $allPages = $this->kirby->collection('allPages');

            // Prepare statements once for reuse
            $stmt = $this->database->prepare('
                INSERT INTO search_index (page_id, title, vernacular_name, description, keywords, main_content, additional_content, key_search_phrases, url, is_members_only, template, last_updated)
                VALUES (:page_id, :title, :vernacular_name, :description, :keywords, :main_content, :additional_content, :key_search_phrases, :url, :is_members_only, :template, :last_updated)
            ');

            $lookupStmt = $this->database->prepare('INSERT OR REPLACE INTO page_lookup (ddb_id, page_id) VALUES (:ddb_id, :page_id)');

            $membersPrefix = $this->getMembersPathPrefix();

            foreach ($allPages as $page) {
                try {
                    // Check if page should be indexed (respects configured exclusions)
                    if (!$this->shouldIndex($page)) {
                        continue;
                    }

                    $indexData = $this->getPageIndexData($page, $membersPrefix);
                    $stmt->execute($indexData);

                    // Add ddbId to page_lookup if present
                    $ddbId = (string)($page->content()->ddbId()->value() ?? '');
                    if (!empty($ddbId)) {
                        $lookupStmt->execute(['ddb_id' => $ddbId, 'page_id' => $page->id()]);
                    }

                    $count++;
                } catch (Throwable $e) {
                    error_log('Failed to index page ' . $page->id() . ': ' . $e->getMessage());
                }
            }

            // Rebuild all_pages table with every page on the site
            $allPagesCount = $this->rebuildAllPages();

            // Update metadata
            $metaStmt = $this->database->prepare('INSERT OR REPLACE INTO search_meta (key, value) VALUES (:key, :value)');
            $metaStmt->execute(['key' => 'last_rebuild', 'value' => date('Y-m-d H:i:s')]);

            $this->database->commit();
        } catch (Throwable $e) {
            $this->database->rollBack();
            throw $e;
        }

        return ['search_index' => $count, 'all_pages' => $allPagesCount];
    }

    /**
     * Ensure the all_pages table exists (for databases created before this feature)
     */
    private function ensureAllPagesTable(): void
    {
        $result = $this->database->query("SELECT name FROM sqlite_master WHERE type='table' AND name='all_pages'");
        if ($result->fetch() === false) {
            $this->database->exec('
                CREATE TABLE all_pages (
                    page_id TEXT PRIMARY KEY,
                    title TEXT NOT NULL DEFAULT ""
                );
            ');
        }
    }

    /**
     * Check whether a page should be excluded from the panel search (all_pages table)
     *
     * @param Page $page The Kirby page to check
     * @return bool True if the page should be excluded
     */
    private function isExcludedFromPanel(Page $page): bool
    {
        return in_array($page->intendedTemplate()->name(), self::PANEL_EXCLUDED_TEMPLATES, true);
    }

    /**
     * Add or update a page in the all_pages table
     *
     * Skips pages with templates listed in PANEL_EXCLUDED_TEMPLATES.
     *
     * @param Page $page The Kirby page to add
     * @return bool True if the entry was added/updated, false if skipped
     */
    private function indexAllPagesEntry(Page $page): bool
    {
        if ($this->isExcludedFromPanel($page)) {
            return false;
        }

        $this->ensureAllPagesTable();
        $stmt = $this->database->prepare(
            'INSERT OR REPLACE INTO all_pages (page_id, title) VALUES (:page_id, :title)'
        );
        return $stmt->execute([
            'page_id' => $page->id(),
            'title' => (string)($page->content()->title()->value() ?? '')
        ]);
    }

    /**
     * Remove a page from the all_pages table
     *
     * @param string $pageId The Kirby page ID to remove
     * @return bool True if deletion was executed
     */
    private function removeAllPagesEntry(string $pageId): bool
    {
        $this->ensureAllPagesTable();
        $stmt = $this->database->prepare('DELETE FROM all_pages WHERE page_id = :page_id');
        return $stmt->execute(['page_id' => $pageId]);
    }

    /**
     * Rebuild the all_pages table with every page on the site
     *
     * Uses site()->index(true) to get all pages including drafts,
     * unlike the search_index which only indexes selected templates.
     * Excludes templates listed in PANEL_EXCLUDED_TEMPLATES.
     *
     * @return int Number of pages indexed
     * @throws Throwable If database operations fail
     */
    public function rebuildAllPages(): int
    {
        $this->ensureAllPagesTable();
        $this->database->exec('DELETE FROM all_pages');

        $stmt = $this->database->prepare(
            'INSERT INTO all_pages (page_id, title) VALUES (:page_id, :title)'
        );

        $count = 0;
        foreach ($this->kirby->site()->index(true) as $page) {
            try {
                if ($this->isExcludedFromPanel($page)) {
                    continue;
                }
                $stmt->execute([
                    'page_id' => $page->id(),
                    'title' => (string)($page->content()->title()->value() ?? '')
                ]);
                $count++;
            } catch (Throwable $e) {
                error_log('Failed to index page in all_pages: ' . $page->id() . ': ' . $e->getMessage());
            }
        }

        return $count;
    }

    /**
     * Get all page IDs from the all_pages table
     *
     * Returns every page on the site, regardless of template or status.
     *
     * @return array<string> Array of all page IDs
     */
    public function getAllPageIds(): array
    {
        $this->ensureAllPagesTable();
        $stmt = $this->database->query('SELECT page_id FROM all_pages');
        return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    }

    /**
     * Search the all_pages table by title
     *
     * Performs a case-insensitive LIKE search on the title column.
     * Suitable for panel page searches where all pages should be searchable.
     *
     * @param string $query Search query string
     * @param int $limit Maximum number of results
     * @param int $offset Offset for pagination
     * @return array{results: array<string>, total: int} Matching page IDs and total count
     */
    public function searchAllPages(string $query, int $limit = 10, int $offset = 0): array
    {
        $this->ensureAllPagesTable();

        $query = trim($query);
        if (empty($query)) {
            return ['results' => [], 'total' => 0];
        }

        $likeParam = '%' . $query . '%';

        $countStmt = $this->database->prepare(
            'SELECT COUNT(*) as total FROM all_pages WHERE title LIKE :query'
        );
        $countStmt->execute(['query' => $likeParam]);
        $total = (int)($countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        $stmt = $this->database->prepare(
            'SELECT page_id FROM all_pages WHERE title LIKE :query ORDER BY title LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':query', $likeParam, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

        return ['results' => $results, 'total' => $total];
    }

    /**
     * Get search index statistics
     *
     * @return array{total_pages: int, last_rebuild: string|null}
     */
    public function getStats(): array
    {
        $countStmt = $this->database->query('SELECT COUNT(*) as total FROM search_index');
        $count = $countStmt->fetch(PDO::FETCH_ASSOC);

        $metaStmt = $this->database->prepare('SELECT value FROM search_meta WHERE key = :key');
        $metaStmt->execute(['key' => 'last_rebuild']);
        $lastRebuild = $metaStmt->fetch(PDO::FETCH_ASSOC);

        return [
            'total_pages' => (int)($count['total'] ?? 0),
            'last_rebuild' => $lastRebuild['value'] ?? null
        ];
    }

    /**
     * Return a paginated list of records from the all_pages table.
     *
     * @param int $page  1-based page number.
     * @param int $pageSize Number of rows per page.
     * @return array{rows: array<int, array<string, mixed>>, columns: string[], total: int, page: int, pageSize: int, totalPages: int}
     */
    public function getRecords(int $page, int $pageSize): array
    {
        $total = (int)($this->database->query('SELECT COUNT(*) FROM all_pages')->fetchColumn() ?? 0);

        $stmt = $this->database->prepare(
            'SELECT page_id, title FROM all_pages ORDER BY page_id ASC LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $pageSize, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'rows'       => $rows,
            'columns'    => ['page_id', 'title'],
            'total'      => $total,
            'page'       => $page,
            'pageSize'   => $pageSize,
            'totalPages' => $pageSize > 0 ? (int)ceil($total / $pageSize) : 0,
        ];
    }
}
