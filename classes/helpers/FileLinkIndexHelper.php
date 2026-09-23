<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

use Exception;
use Kirby\Cms\Page;
use Kirby\Filesystem\Dir;
use Kirby\Filesystem\F;
use PDO;
use Throwable;

/**
 * SQLite-backed reverse-link index: "which pages link to this file?".
 *
 * Built to answer the File Archive use case — given an archive file, find every
 * page on the site that links to it (so editors can fix wording or avoid dead
 * links when a file changes or is removed).
 *
 * Two link forms are tracked:
 *  - Kirby internal links: <code>file://UUID</code> tokens embedded in any field
 *    (writer text, blocks, structures) — matched directly on the file's UUID.
 *  - Hard-coded permanent URLs: <code>/files/&lt;permanentUrl&gt;</code> references,
 *    resolved to a file UUID via a permanentUrl => uuid map supplied by the caller.
 *
 * The index holds one row per (file_uuid, page_id, link_type). Build it once with
 * rebuildIndex(); keep it current with indexPage()/removePage() wired to page
 * lifecycle hooks. Query with getLinkingPages().
 *
 * @package BSBI\WebBase\helpers
 */
class FileLinkIndexHelper
{
    private const string DATABASE_DIR = '/logs/content-indexes/';
    private const string DATABASE_FILE = 'filelinks.sqlite';
    private const string LINKS_TABLE = 'file_links';
    private const string META_TABLE = 'file_links_meta';

    /** Link-type recorded for a file:// token embedded in page content. */
    private const string LINK_TYPE_UUID = 'uuid';
    /** Link-type recorded for a hard-coded /files/<permanentUrl> reference. */
    private const string LINK_TYPE_PERMANENT_URL = 'permanent_url';
    /** Link-type recorded for a file wrapped by a file_link permanent-URL page. */
    private const string LINK_TYPE_FILE_LINK = 'file_link';

    /** Template name of the permanent-URL wrapper page that wraps a single file. */
    private const string FILE_LINK_TEMPLATE = 'file_link';

    /**
     * Templates excluded from the index.
     *
     * These are system, wrapper, and redirect pages that are not meaningful
     * "pages that link to a file". Note that file_link is NOT excluded: a
     * file_link page is a permanent-URL wrapper around a single file and is
     * often the only direct reference to that file, so it must be indexed (see
     * #535) — it is handled specially via indexFileLinkContent() and recorded
     * with the file_link link-type. Mirrors the site's allPages collection
     * exclusions otherwise.
     *
     * @var array<int, string>
     */
    private const array EXCLUDED_TEMPLATES = [
        'basket', 'basket_item', 'basket_page', 'blog_month_folder', 'blog_year_folder',
        'checkout', 'error', 'error-500', 'file_archive', 'form_training',
        'form_submission', 'image_bank', 'login', 'order', 'orders', 'page_link',
    ];

    private PDO $database;

    /**
     * @param string|null $databaseFilePath Absolute path to the SQLite file.
     *   Defaults to the standard location under the Kirby site root. Pass an
     *   explicit path (e.g. a temp file) to use the class without a Kirby
     *   bootstrap, as the tests do.
     * @throws Exception If the database directory cannot be created.
     */
    public function __construct(?string $databaseFilePath = null)
    {
        $this->initializeDatabase($databaseFilePath);
    }

    /**
     * Return true if the file-link index database file already exists.
     *
     * Use this to avoid instantiating the helper (and triggering a query) when
     * the index has not yet been built — callers can then skip indexing or show
     * a "not built yet" message.
     *
     * @return bool
     */
    public static function isIndexReady(): bool
    {
        $file = SitePaths::resolve(kirby(), self::DATABASE_DIR . self::DATABASE_FILE);
        return F::exists($file);
    }

    // ── Token extraction (Kirby-independent, static for testability) ────────

    /**
     * Extract distinct Kirby file UUIDs from a block of content text.
     *
     * Matches <code>file://UUID</code> tokens. UUIDs are alphanumeric (Kirby
     * generates 16-character lowercase ids, but the pattern is permissive to
     * cope with any future format).
     *
     * @param string $text Raw content text to scan.
     * @return string[] Distinct UUIDs in order of first appearance.
     */
    public static function extractFileUuids(string $text): array
    {
        if (!preg_match_all('#file://([a-zA-Z0-9]+)#', $text, $matches)) {
            return [];
        }
        return array_values(array_unique($matches[1]));
    }

    /**
     * Extract distinct permanent-URL segments from a block of content text.
     *
     * Matches the part after <code>/files/</code> in any reference (relative or
     * absolute), stopping at whitespace, quotes, or angle/round brackets.
     *
     * @param string $text Raw content text to scan.
     * @return string[] Distinct segments in order of first appearance.
     */
    public static function extractPermanentUrlSegments(string $text): array
    {
        if (!preg_match_all('#/files/([^\s"\'<>)\\\\]+)#', $text, $matches)) {
            return [];
        }
        return array_values(array_unique($matches[1]));
    }

    /**
     * Return true if the given template name is excluded from the index.
     *
     * @param string $templateName The page's intended template name.
     * @return bool
     */
    public static function isExcludedTemplate(string $templateName): bool
    {
        return in_array($templateName, self::EXCLUDED_TEMPLATES, true);
    }

    // ── Indexing ────────────────────────────────────────────────────────────

    /**
     * Index (or re-index) all file links found in a single page's content text.
     *
     * Existing rows for the page are removed first, so the call is idempotent and
     * safe for both full rebuilds and incremental updates after an edit.
     *
     * @param string                $pageId           The Kirby page ID (e.g. "about/team").
     * @param string                $contentText      The page's combined raw content text.
     * @param array<string, string> $permanentUrlMap  Map of permanentUrl segment => file UUID.
     * @return int Number of link rows inserted for the page.
     */
    public function indexPageContent(string $pageId, string $contentText, array $permanentUrlMap): int
    {
        $this->removePage($pageId);

        $rows = [];
        foreach (self::extractFileUuids($contentText) as $uuid) {
            $rows[] = [$uuid, self::LINK_TYPE_UUID];
        }
        foreach (self::extractPermanentUrlSegments($contentText) as $segment) {
            if (isset($permanentUrlMap[$segment])) {
                $rows[] = [$permanentUrlMap[$segment], self::LINK_TYPE_PERMANENT_URL];
            }
        }

        return $this->insertLinks($pageId, $rows);
    }

    /**
     * Index (or re-index) the file wrapped by a single file_link page.
     *
     * A file_link page is a permanent-URL wrapper whose `file` field holds a
     * single <code>file://UUID</code> token. It is frequently the only direct
     * reference to that file, so the file must be recorded as used — attributed
     * to the wrapper page with the dedicated file_link link-type so the Panel can
     * present it distinctly from ordinary content references (see #535).
     *
     * Existing rows for the page are removed first, so the call is idempotent.
     *
     * @param string $pageId      The file_link page's Kirby ID.
     * @param string $contentText The file_link page's combined raw content text.
     * @return int Number of link rows inserted for the page.
     */
    public function indexFileLinkContent(string $pageId, string $contentText): int
    {
        $this->removePage($pageId);

        $rows = [];
        foreach (self::extractFileUuids($contentText) as $uuid) {
            $rows[] = [$uuid, self::LINK_TYPE_FILE_LINK];
        }

        return $this->insertLinks($pageId, $rows);
    }

    /**
     * Insert link rows for a page, ignoring duplicates.
     *
     * @param string                          $pageId The Kirby page ID the links belong to.
     * @param array<int, array{0: string, 1: string}> $rows   List of [file UUID, link type] pairs.
     * @return int Number of rows actually inserted (duplicates are ignored).
     */
    private function insertLinks(string $pageId, array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $stmt = $this->database->prepare(
            'INSERT OR IGNORE INTO ' . self::LINKS_TABLE
                . ' (file_uuid, page_id, link_type) VALUES (:file_uuid, :page_id, :link_type)'
        );
        $inserted = 0;
        foreach ($rows as [$uuid, $type]) {
            $stmt->execute(['file_uuid' => $uuid, 'page_id' => $pageId, 'link_type' => $type]);
            $inserted += $stmt->rowCount();
        }
        return $inserted;
    }

    /**
     * Remove all index rows for the given page.
     *
     * @param string $pageId The Kirby page ID.
     */
    public function removePage(string $pageId): void
    {
        $stmt = $this->database->prepare(
            'DELETE FROM ' . self::LINKS_TABLE . ' WHERE page_id = :page_id'
        );
        $stmt->execute(['page_id' => $pageId]);
    }

    // ── Querying ──────────────────────────────────────────────────────────

    /**
     * Return the pages linking to the file with the given UUID.
     *
     * Each page appears once, with a comma-separated list of the link types
     * ("uuid", "permanent_url") through which it references the file.
     *
     * @param string $fileUuid The file's Kirby UUID.
     * @return array<int, array{pageId: string, linkTypes: string}>
     */
    public function getLinkingPages(string $fileUuid): array
    {
        $stmt = $this->database->prepare(
            'SELECT page_id, GROUP_CONCAT(DISTINCT link_type) AS link_types'
                . ' FROM ' . self::LINKS_TABLE
                . ' WHERE file_uuid = :file_uuid'
                . ' GROUP BY page_id ORDER BY page_id ASC'
        );
        $stmt->execute(['file_uuid' => $fileUuid]);

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[] = [
                'pageId'    => $row['page_id'],
                'linkTypes' => $row['link_types'],
            ];
        }
        return $result;
    }

    /**
     * Return statistics about the current index state.
     *
     * @return array{total_files: int, total_pages: int, total_links: int, last_rebuild: string|null}
     */
    public function getStats(): array
    {
        $files = (int)($this->database->query(
            'SELECT COUNT(DISTINCT file_uuid) FROM ' . self::LINKS_TABLE
        )->fetchColumn() ?? 0);
        $pages = (int)($this->database->query(
            'SELECT COUNT(DISTINCT page_id) FROM ' . self::LINKS_TABLE
        )->fetchColumn() ?? 0);
        $links = (int)($this->database->query(
            'SELECT COUNT(*) FROM ' . self::LINKS_TABLE
        )->fetchColumn() ?? 0);

        $lastRebuild = null;
        try {
            $stmt = $this->database->prepare(
                'SELECT value FROM ' . self::META_TABLE . ' WHERE key = :key'
            );
            $stmt->execute(['key' => 'last_rebuild']);
            $value = $stmt->fetchColumn();
            $lastRebuild = $value === false ? null : (string)$value;
        } catch (Throwable) {
            // Meta table may not exist on a very old index.
        }

        return [
            'total_files'  => $files,
            'total_pages'  => $pages,
            'total_links'  => $links,
            'last_rebuild' => $lastRebuild,
        ];
    }

    // ── Kirby-facing orchestration ──────────────────────────────────────────

    /**
     * Rebuild the entire index from scratch by scanning every page on the site.
     *
     * Uses site()->index(true) so that drafts and unlisted pages are included —
     * a draft can link a file too. This full scan is expensive and is only run
     * on an explicit rebuild; incremental hook updates keep the index current
     * between rebuilds.
     *
     * @return int Number of pages that contributed at least one link.
     * @throws Throwable If the database transaction fails.
     */
    public function rebuildIndex(): int
    {
        $permanentUrlMap = $this->buildPermanentUrlMap();

        $this->database->beginTransaction();
        try {
            $this->database->exec('DELETE FROM ' . self::LINKS_TABLE);

            $pagesWithLinks = 0;
            foreach (kirby()->site()->index(true) as $page) {
                try {
                    if ($this->indexSinglePage($page, $permanentUrlMap) > 0) {
                        $pagesWithLinks++;
                    }
                } catch (Throwable $e) {
                    error_log('FileLinkIndexHelper: failed to index page ' . $page->id() . ': ' . $e->getMessage());
                }
            }

            $this->updateMeta();
            $this->database->commit();
        } catch (Throwable $e) {
            $this->database->rollBack();
            throw $e;
        }

        return $pagesWithLinks;
    }

    /**
     * Re-index a single Kirby page (for use in page lifecycle hooks).
     *
     * @param Page $page The page that was created or updated.
     */
    public function indexPage(Page $page): void
    {
        $this->indexSinglePage($page, $this->buildPermanentUrlMap());
    }

    /**
     * Index a single Kirby page according to its template.
     *
     * Routing:
     *  - file_link wrapper pages are indexed via indexFileLinkContent() so the
     *    file they wrap is recorded as used (with the file_link link-type).
     *  - other excluded templates clear any stale rows and contribute nothing.
     *  - all remaining pages are scanned for file:// and /files/ references.
     *
     * Shared by rebuildIndex() (full scan) and indexPage() (incremental hook).
     *
     * @param Page                  $page            The page to index.
     * @param array<string, string> $permanentUrlMap Map of permanentUrl segment => file UUID.
     * @return int Number of link rows inserted for the page.
     */
    private function indexSinglePage(Page $page, array $permanentUrlMap): int
    {
        $template = $page->intendedTemplate()->name();

        if ($template === self::FILE_LINK_TEMPLATE) {
            return $this->indexFileLinkContent($page->id(), $this->extractContentText($page));
        }

        // Excluded templates must never appear as results. Clear any existing
        // rows in case the template changed.
        if (self::isExcludedTemplate($template)) {
            $this->removePage($page->id());
            return 0;
        }

        return $this->indexPageContent(
            $page->id(),
            $this->extractContentText($page),
            $permanentUrlMap
        );
    }

    /**
     * Build a map of permanent-URL segment => file UUID from the file archive.
     *
     * Only archive files with a non-empty permanentUrl field are included.
     *
     * @param string $archivePageId Page ID of the file archive parent.
     * @return array<string, string>
     */
    public function buildPermanentUrlMap(string $archivePageId = 'file-archive'): array
    {
        $map = [];
        $archive = kirby()->page($archivePageId);
        if ($archive === null) {
            return $map;
        }
        foreach ($archive->files() as $file) {
            $permanentUrl = trim((string)$file->content()->get('permanentUrl')->value());
            $uuid = $file->uuid()?->id();
            if ($permanentUrl !== '' && $uuid !== null) {
                $map[$permanentUrl] = $uuid;
            }
        }
        return $map;
    }

    /**
     * Combine all of a page's content field values (across all languages) into a
     * single string suitable for token scanning.
     *
     * Block and structure fields are stored as JSON within their field values, so
     * the file:// tokens they contain are captured by flattening the value array.
     *
     * @param Page $page
     * @return string
     */
    private function extractContentText(Page $page): string
    {
        $parts = [];
        $languages = kirby()->languages();

        if ($languages->count() > 0) {
            foreach ($languages as $language) {
                $parts[] = $this->flattenContent($page->content($language->code())->toArray());
            }
        } else {
            $parts[] = $this->flattenContent($page->content()->toArray());
        }

        return implode(' ', $parts);
    }

    /**
     * Recursively flatten a content array into a single space-separated string.
     *
     * @param array<int|string, mixed> $content
     * @return string
     */
    private function flattenContent(array $content): string
    {
        $parts = [];
        array_walk_recursive($content, function ($value) use (&$parts): void {
            if (is_scalar($value)) {
                $parts[] = (string)$value;
            }
        });
        return implode(' ', $parts);
    }

    // ── Schema / connection ─────────────────────────────────────────────────

    /**
     * Open (or create) the SQLite database and ensure the schema exists.
     *
     * @param string|null $databaseFilePath Explicit path, or null to derive from the Kirby site root.
     * @throws Exception If the database directory cannot be created.
     */
    private function initializeDatabase(?string $databaseFilePath): void
    {
        if ($databaseFilePath === null) {
            $dir              = SitePaths::resolve(kirby(), self::DATABASE_DIR);
            $databaseFilePath = $dir . self::DATABASE_FILE;
        } else {
            $dir = dirname($databaseFilePath);
        }

        $needsCreate = !F::exists($databaseFilePath);

        if ($needsCreate && !is_dir($dir)) {
            if (Dir::make($dir) === false) {
                throw new Exception("Failed to create file-link index directory: $dir");
            }
        }

        $this->database = new PDO('sqlite:' . $databaseFilePath);
        $this->database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->database->exec('PRAGMA journal_mode=WAL');

        $this->createSchema();
    }

    /**
     * Create the links and meta tables and their indexes if absent.
     */
    private function createSchema(): void
    {
        $this->database->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::LINKS_TABLE . ' (
                id        INTEGER PRIMARY KEY AUTOINCREMENT,
                file_uuid TEXT NOT NULL,
                page_id   TEXT NOT NULL,
                link_type TEXT NOT NULL DEFAULT \'uuid\'
            )'
        );

        $this->database->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_file_links_unique'
                . ' ON ' . self::LINKS_TABLE . ' (file_uuid, page_id, link_type)'
        );
        $this->database->exec(
            'CREATE INDEX IF NOT EXISTS idx_file_links_uuid'
                . ' ON ' . self::LINKS_TABLE . ' (file_uuid)'
        );
        $this->database->exec(
            'CREATE INDEX IF NOT EXISTS idx_file_links_page'
                . ' ON ' . self::LINKS_TABLE . ' (page_id)'
        );

        $this->database->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::META_TABLE . ' (
                key   TEXT PRIMARY KEY,
                value TEXT
            )'
        );
    }

    /**
     * Upsert the last_rebuild timestamp in the meta table.
     */
    private function updateMeta(): void
    {
        $stmt = $this->database->prepare(
            'INSERT OR REPLACE INTO ' . self::META_TABLE . ' (key, value) VALUES (:key, :value)'
        );
        $stmt->execute(['key' => 'last_rebuild', 'value' => date('Y-m-d H:i:s')]);
    }
}
