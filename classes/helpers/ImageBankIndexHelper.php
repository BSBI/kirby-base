<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

use Exception;
use Kirby\Cms\File;
use Kirby\Filesystem\Dir;
use Kirby\Filesystem\F;
use PDO;
use PDOException;
use Throwable;

/**
 * SQLite-backed index for the imageBank panel section.
 *
 * Maintains three tables:
 *
 * - imagebank_taxa_map: one row per (filename, taxa_title, taxa_id) — used for the
 *   fast O(1) taxa-by-title lookup on species pages (getFilenamesForTaxa) and for
 *   panel taxa filter (getFilteredResults with taxa_id).
 *
 * - imagebank_files: one row per file with all metadata needed for panel display
 *   (title, photographer, tags_csv, url, panel_url). Used for all non-taxa panel
 *   queries.
 *
 * - imagebank_file_tags: one row per (filename, tag) — for efficient tag filtering.
 *
 * Build the index once via rebuildIndex(), then keep it current via indexFile()
 * and removeFile() wired to Kirby file hooks.
 *
 * @package BSBI\WebBase\helpers
 */
class ImageBankIndexHelper
{
    private const string DATABASE_DIR = '/logs/content-indexes/';
    private const string DATABASE_FILE = 'imagebank.sqlite';
    private const string TABLE_NAME = 'imagebank_taxa_map';
    private const string META_TABLE = 'imagebank_index_meta';
    private const string FILES_TABLE = 'imagebank_files';
    private const string TAGS_TABLE = 'imagebank_file_tags';

    private PDO $database;
    private string $collectionName;
    private string $imageBankPageId;

    /**
     * @param string|null $databaseFilePath Absolute path to the SQLite file.
     *   Defaults to the standard location under the Kirby site root.
     *   Pass an explicit path (e.g. a temp file) to make the class testable
     *   without a Kirby bootstrap.
     * @param string $collectionName Name of the Kirby collection providing imageBank files.
     * @param string $imageBankPageId Page ID of the imageBank page (used for file panel URLs).
     * @throws Exception If the database directory cannot be created or the connection fails.
     */
    public function __construct(
        ?string $databaseFilePath = null,
        string $collectionName = 'imageBank',
        string $imageBankPageId = 'image-bank'
    ) {
        $this->collectionName  = $collectionName;
        $this->imageBankPageId = $imageBankPageId;
        $this->initializeDatabase($databaseFilePath);
    }

    /**
     * Return true if the imageBank index database file already exists.
     *
     * Use this to avoid instantiating the helper when the index has not yet
     * been built — callers can fall back to the slow Kirby collection scan.
     *
     * @return bool
     */
    public static function isIndexReady(): bool
    {
        $file = SitePaths::resolve(kirby(), self::DATABASE_DIR . self::DATABASE_FILE);
        return F::exists($file);
    }

    /**
     * Return true if the panel-specific tables (imagebank_files) exist in the index.
     *
     * An older index built before the panel tables were added will return false.
     * Callers should trigger a rebuild if this returns false.
     *
     * @return bool
     */
    public static function isPanelIndexReady(): bool
    {
        if (!self::isIndexReady()) {
            return false;
        }
        try {
            $index     = new self();
            $tableStmt = $index->database->query(
                "SELECT name FROM sqlite_master WHERE type='table' AND name='" . self::FILES_TABLE . "'"
            );
            if ($tableStmt === false || $tableStmt->fetchColumn() === false) {
                return false;
            }
            // Confirm the table is actually populated (migration may create it empty)
            $countStmt = $index->database->query('SELECT COUNT(*) FROM ' . self::FILES_TABLE);
            return $countStmt !== false && (int)$countStmt->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Rebuild the entire imageBank index from scratch.
     *
     * Clears and repopulates imagebank_taxa_map, imagebank_files, and
     * imagebank_file_tags from the live Kirby imageBank collection.
     *
     * @return int Number of imageBank files processed.
     * @throws Throwable If the database transaction fails.
     */
    public function rebuildIndex(): int
    {
        $this->database->beginTransaction();

        try {
            $this->database->exec('DELETE FROM ' . self::TABLE_NAME);
            $this->database->exec('DELETE FROM ' . self::FILES_TABLE);
            $this->database->exec('DELETE FROM ' . self::TAGS_TABLE);

            $imageBank = kirby()->collection($this->collectionName);
            $count     = 0;

            foreach ($imageBank as $file) {
                /** @var File $file */
                $this->insertFileEntries($file);
                $count++;
            }

            $this->updateMeta();
            $this->database->commit();
        } catch (Throwable $e) {
            $this->database->rollBack();
            throw $e;
        }

        return $count;
    }

    /**
     * Update the index for a single imageBank file.
     *
     * Removes all existing entries for the file then re-inserts current data.
     *
     * @param File $file The imageBank file to index.
     */
    public function indexFile(File $file): void
    {
        $this->removeFile($file->filename());
        $this->insertFileEntries($file);
    }

    /**
     * Remove all index entries for the given filename.
     *
     * @param string $filename The file's filename (e.g. "photo.jpg").
     */
    public function removeFile(string $filename): void
    {
        foreach ([self::TABLE_NAME, self::FILES_TABLE, self::TAGS_TABLE] as $table) {
            $stmt = $this->database->prepare("DELETE FROM $table WHERE filename = :filename");
            $stmt->execute(['filename' => $filename]);
        }
    }

    /**
     * Return statistics about the current index state.
     *
     * @return array{total_files: int, total_taxa: int, last_rebuild: string|null}
     */
    public function getStats(): array
    {
        $filesStmt = $this->database->query(
            'SELECT COUNT(DISTINCT filename) as total FROM ' . self::TABLE_NAME
        );
        $filesRow = $filesStmt->fetch(PDO::FETCH_ASSOC);

        $taxaStmt = $this->database->query(
            'SELECT COUNT(DISTINCT taxa_title) as total FROM ' . self::TABLE_NAME
        );
        $taxaRow = $taxaStmt->fetch(PDO::FETCH_ASSOC);

        $lastRebuild = null;
        try {
            $metaStmt = $this->database->prepare(
                'SELECT value FROM ' . self::META_TABLE . ' WHERE key = :key'
            );
            $metaStmt->execute(['key' => 'last_rebuild']);
            $metaResult = $metaStmt->fetch(PDO::FETCH_ASSOC);
            $lastRebuild = $metaResult['value'] ?? null;
        } catch (Throwable) {
            // Meta table may not exist yet
        }

        return [
            'total_files'  => (int)($filesRow['total'] ?? 0),
            'total_taxa'   => (int)($taxaRow['total'] ?? 0),
            'last_rebuild' => $lastRebuild,
        ];
    }

    /**
     * Return a paginated list of records from the imagebank_files table.
     *
     * @param int $page     1-based page number.
     * @param int $pageSize Number of rows per page.
     * @return array{rows: array<int, array<string, mixed>>, columns: string[], total: int, page: int, pageSize: int, totalPages: int}
     */
    public function getRecords(int $page, int $pageSize): array
    {
        $total = (int)($this->database->query(
            'SELECT COUNT(*) FROM ' . self::FILES_TABLE
        )->fetchColumn() ?? 0);

        $stmt = $this->database->prepare(
            'SELECT filename, title, photographer, tags_csv FROM ' . self::FILES_TABLE
            . ' ORDER BY filename ASC LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $pageSize, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'rows'       => $rows,
            'columns'    => ['filename', 'title', 'photographer', 'tags_csv'],
            'total'      => $total,
            'page'       => $page,
            'pageSize'   => $pageSize,
            'totalPages' => $pageSize > 0 ? (int)ceil($total / $pageSize) : 0,
        ];
    }

    /**
     * Return filenames of all imageBank files tagged with the given taxa title.
     *
     * Performs an O(1) SQLite lookup using the taxa_title index.
     *
     * @param string $taxaTitle The taxa title to look up (e.g. "Anemone nemorosa").
     * @return string[] Filenames in insertion order.
     */
    public function getFilenamesForTaxa(string $taxaTitle): array
    {
        $stmt = $this->database->prepare(
            'SELECT filename FROM ' . self::TABLE_NAME
                . ' WHERE taxa_title = :taxa_title ORDER BY sort_order ASC'
        );
        $stmt->execute(['taxa_title' => $taxaTitle]);
        $result = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return is_array($result) ? $result : [];
    }

    /**
     * Return filter dropdown options for the image bank panel section.
     *
     * Queries photographer, tags, and taxa options from the SQLite index, then
     * remaps them to filter keys using $filterDefs. This means a filter key such
     * as 'excludePhotographer' with 'field: photographer' receives the same option
     * list as the 'photographer' filter key.
     *
     * When $filterDefs is empty the raw content-field-keyed array is returned for
     * backwards compatibility.
     *
     * @param array<string, array<string, mixed>> $filterDefs Filter definitions keyed by filter key.
     * @return array<string, array<int, array{value: string, text: string}>>
     */
    public function getPanelOptions(array $filterDefs = []): array
    {
        $photographerStmt = $this->database->query(
            "SELECT DISTINCT photographer FROM " . self::FILES_TABLE
                . " WHERE photographer != '' ORDER BY photographer ASC"
        );
        $photographers = [];
        foreach ($photographerStmt->fetchAll(PDO::FETCH_COLUMN) as $val) {
            $photographers[] = ['value' => $val, 'text' => $val];
        }

        $tagsStmt = $this->database->query(
            'SELECT DISTINCT tag FROM ' . self::TAGS_TABLE . ' ORDER BY tag ASC'
        );
        $tags = [];
        foreach ($tagsStmt->fetchAll(PDO::FETCH_COLUMN) as $val) {
            $tags[] = ['value' => $val, 'text' => $val];
        }

        $taxaStmt = $this->database->query(
            "SELECT DISTINCT taxa_id, taxa_title FROM " . self::TABLE_NAME
                . " WHERE taxa_id != '' ORDER BY taxa_title ASC"
        );
        $taxa = [];
        foreach ($taxaStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $taxa[] = ['value' => $row['taxa_id'], 'text' => $row['taxa_title']];
        }

        $rawOptions = [
            'photographer' => $photographers,
            'tags'         => $tags,
            'taxa'         => $taxa,
        ];

        if (empty($filterDefs)) {
            return $rawOptions;
        }

        // Remap raw options to each filter key, respecting the 'field' override.
        $result = [];
        foreach ($filterDefs as $filterKey => $def) {
            $contentField = $def['field'] ?? $filterKey;
            if (isset($rawOptions[$contentField])) {
                $result[$filterKey] = $rawOptions[$contentField];
            }
        }
        return $result;
    }

    /**
     * Return a paginated, filtered, and sorted list of imageBank files.
     *
     * Filters:
     *  - active['photographer']: match on photographer field (= or != depending on filterDefs mode).
     *  - active['tags']: match on a single tag value.
     *  - active['taxa']: match on taxa page ID (e.g. 'taxa/quercus-robur').
     *
     * Each returned item has the shape:
     * <code>
     * [
     *   'id'            => string,  // same as filename for compat with filteredfiles Vue component
     *   'title'         => string,
     *   'filename'      => string,
     *   'panelUrl'      => string,
     *   'thumbUrl'      => string,
     *   'displayValues' => ['filename' => string, 'photographer' => string, 'tags' => string],
     * ]
     * </code>
     *
     * @param array<string, string>               $active      Active filter values keyed by field name.
     * @param string                              $search      Freetext search string (matches title/filename).
     * @param string                              $sortField   Column to sort by (filename|title|photographer).
     * @param string                              $sortDir     'asc' or 'desc'.
     * @param int                                 $page        1-based page number.
     * @param int                                 $pageSize    Items per page.
     * @param array<string, array<string, mixed>> $filterDefs  Filter definitions (used to determine include/exclude mode).
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, pageSize: int, totalPages: int}
     */
    public function getPanelResults(
        array $active,
        string $search,
        string $sortField,
        string $sortDir,
        int $page,
        int $pageSize,
        array $filterDefs = []
    ): array {
        $empty = ['items' => [], 'total' => 0, 'page' => 1, 'pageSize' => $pageSize, 'totalPages' => 0];

        $allowedSortFields = ['filename', 'title', 'photographer'];
        if (!in_array($sortField, $allowedSortFields, true)) {
            $sortField = 'filename';
        }
        $sortDir = strtolower($sortDir) === 'desc' ? 'DESC' : 'ASC';

        [$joins, $where, $params] = $this->buildQueryParts($active, $search, $filterDefs);

        $countSql = "SELECT COUNT(DISTINCT f.filename) FROM " . self::FILES_TABLE . " f"
            . $joins . " WHERE " . $where;

        try {
            $countStmt = $this->database->prepare($countSql);
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();
        } catch (PDOException $e) {
            error_log('ImageBankIndexHelper::getPanelResults count query failed: ' . $e->getMessage());
            return $empty;
        }

        $totalPages = $pageSize > 0 ? (int)ceil($total / $pageSize) : 0;
        $offset     = ($page - 1) * $pageSize;

        $resultSql = "SELECT DISTINCT f.filename, f.title, f.photographer, f.tags_csv, f.url, f.thumb_url, f.panel_url"
            . " FROM " . self::FILES_TABLE . " f"
            . $joins . " WHERE " . $where
            . " ORDER BY f.$sortField $sortDir"
            . " LIMIT :limit OFFSET :offset";

        try {
            $stmt = $this->database->prepare($resultSql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('ImageBankIndexHelper::getPanelResults results query failed: ' . $e->getMessage());
            return $empty;
        }

        $imageBankPage = kirby()->page($this->imageBankPageId);
        $thumbBase     = kirby()->url() . '/image-bank-thumb/';
        $items         = [];
        foreach ($rows as $row) {
            $kirbyFile = $imageBankPage?->file($row['filename']);
            $thumbUrl  = $thumbBase . rawurlencode($row['filename']);
            $panelUrl  = $kirbyFile?->panel()->url() ?? $row['panel_url'];

            $items[] = [
                'id'            => $row['filename'],
                'title'         => $row['title'],
                'filename'      => $row['filename'],
                'panelUrl'      => $panelUrl,
                'thumbUrl'      => $thumbUrl,
                'displayValues' => [
                    'filename'     => $row['filename'],
                    'photographer' => $row['photographer'],
                    'tags'         => $row['tags_csv'],
                ],
            ];
        }

        return [
            'items'      => $items,
            'total'      => $total,
            'page'       => $page,
            'pageSize'   => $pageSize,
            'totalPages' => $totalPages,
        ];
    }

    /**
     * Build JOIN, WHERE, and parameter arrays for a panel query.
     *
     * Iterates filterDefs so that filter keys with a 'field' override (e.g.
     * excludePhotographer → field: photographer) correctly map to the right SQL
     * column. PDO parameters are named after the filter key to stay unique when
     * the same content field is used by more than one filter definition.
     *
     * @param array<string, string>               $active      Active filter values keyed by filter key.
     * @param string                              $search      Freetext search string.
     * @param array<string, array<string, mixed>> $filterDefs  Filter definitions (keyed by filter key).
     * @return array{0: string, 1: string, 2: array<string, mixed>}
     *   [joins string, where string, params array]
     */
    private function buildQueryParts(array $active, string $search, array $filterDefs = []): array
    {
        $joins     = '';
        $wheres    = ['1=1'];
        $params    = [];
        $joinIndex = 0;

        foreach ($filterDefs as $filterKey => $def) {
            $value = trim($active[$filterKey] ?? '');
            if ($value === '') {
                continue;
            }

            $contentField = $def['field'] ?? $filterKey;
            $exclude      = (($def['mode'] ?? 'include') === 'exclude');
            $paramKey     = ':' . $filterKey;

            if ($contentField === 'photographer') {
                $op       = $exclude ? '!=' : '=';
                $wheres[] = "f.photographer $op $paramKey";
                $params[$paramKey] = $value;
            } elseif ($contentField === 'tags') {
                if ($exclude) {
                    $wheres[] = 'f.filename NOT IN (SELECT filename FROM ' . self::TAGS_TABLE . " WHERE tag = $paramKey)";
                } else {
                    $alias    = 'ftt' . $joinIndex++;
                    $joins   .= ' INNER JOIN ' . self::TAGS_TABLE . " $alias ON f.filename = $alias.filename";
                    $wheres[] = "$alias.tag = $paramKey";
                }
                $params[$paramKey] = $value;
            } elseif ($contentField === 'taxa') {
                if ($exclude) {
                    $wheres[] = 'f.filename NOT IN (SELECT filename FROM ' . self::TABLE_NAME . " WHERE taxa_id = $paramKey)";
                } else {
                    $alias    = 'tm' . $joinIndex++;
                    $joins   .= ' INNER JOIN ' . self::TABLE_NAME . " $alias ON f.filename = $alias.filename";
                    $wheres[] = "$alias.taxa_id = $paramKey";
                }
                $params[$paramKey] = $value;
            }
        }

        if ($search !== '') {
            $wheres[]          = '(f.title LIKE :search OR f.filename LIKE :search)';
            $params[':search'] = '%' . $search . '%';
        }

        return [$joins, implode(' AND ', $wheres), $params];
    }

    /**
     * Insert all index entries for a file (does not remove existing entries first).
     *
     * Populates imagebank_taxa_map (with taxa_id), imagebank_files, and
     * imagebank_file_tags.
     *
     * @param File $file
     */
    private function insertFileEntries(File $file): void
    {
        $filename = $file->filename();

        // ── imagebank_taxa_map ────────────────────────────────────────────
        $linkedPages = $file->content()->get('taxa')->toPages();
        $sortOrder   = 0;

        $taxaStmt = $this->database->prepare(
            'INSERT INTO ' . self::TABLE_NAME
                . ' (filename, taxa_title, taxa_id, sort_order)'
                . ' VALUES (:filename, :taxa_title, :taxa_id, :sort_order)'
        );

        foreach ($linkedPages as $linkedPage) {
            $taxaStmt->execute([
                'filename'   => $filename,
                'taxa_title' => $linkedPage->title()->value(),
                'taxa_id'    => $linkedPage->id(),
                'sort_order' => $sortOrder,
            ]);
            $sortOrder++;
        }

        // ── imagebank_files ───────────────────────────────────────────────
        $imageTitle = trim($file->content()->get('imageTitle')->value() ?? '');
        $title      = $imageTitle !== '' ? $imageTitle : $filename;

        $photographer = trim($file->content()->get('photographer')->value() ?? '');

        $rawTags = $file->content()->get('tags')->value() ?? '';
        $tagList = $rawTags !== '' ? array_filter(array_map('trim', explode(',', $rawTags))) : [];
        $tagsCsv = implode(', ', $tagList);

        $fileStmt = $this->database->prepare(
            'INSERT INTO ' . self::FILES_TABLE
                . ' (filename, title, photographer, tags_csv, url, thumb_url, panel_url)'
                . ' VALUES (:filename, :title, :photographer, :tags_csv, :url, :thumb_url, :panel_url)'
        );
        $fileUrl = $file->url();
        $fileStmt->execute([
            'filename'     => $filename,
            'title'        => $title,
            'photographer' => $photographer,
            'tags_csv'     => $tagsCsv,
            'url'          => $fileUrl,
            'thumb_url'    => $fileUrl,
            'panel_url'    => $file->panel()->url(),
        ]);

        // ── imagebank_file_tags ───────────────────────────────────────────
        if ($tagList !== []) {
            $tagStmt = $this->database->prepare(
                'INSERT INTO ' . self::TAGS_TABLE . ' (filename, tag) VALUES (:filename, :tag)'
            );
            foreach ($tagList as $tag) {
                $tagStmt->execute(['filename' => $filename, 'tag' => $tag]);
            }
        }
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

    /**
     * Open (or create) the SQLite database, ensure the schema exists, and run
     * any pending migrations (e.g. adding taxa_id or the panel tables).
     *
     * @param string|null $databaseFilePath Explicit path, or null to derive from Kirby site root.
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
                throw new Exception("Failed to create imageBank index directory: $dir");
            }
        }

        $this->database = new PDO('sqlite:' . $databaseFilePath);
        $this->database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->database->exec('PRAGMA journal_mode=WAL');

        if ($needsCreate) {
            $this->createSchema();
        } else {
            $this->runMigrations();
        }
    }

    /**
     * Create all tables and indexes from scratch.
     */
    private function createSchema(): void
    {
        $this->database->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE_NAME . ' (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                filename   TEXT    NOT NULL,
                taxa_title TEXT    NOT NULL,
                taxa_id    TEXT    NOT NULL DEFAULT \'\',
                sort_order INTEGER NOT NULL DEFAULT 0
            )'
        );

        $this->database->exec(
            'CREATE INDEX IF NOT EXISTS idx_imagebank_taxa_title'
                . ' ON ' . self::TABLE_NAME . ' (taxa_title)'
        );

        $this->database->exec(
            'CREATE INDEX IF NOT EXISTS idx_imagebank_filename'
                . ' ON ' . self::TABLE_NAME . ' (filename)'
        );

        $this->database->exec(
            'CREATE INDEX IF NOT EXISTS idx_imagebank_taxa_id'
                . ' ON ' . self::TABLE_NAME . ' (taxa_id)'
        );

        $this->database->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::FILES_TABLE . ' (
                filename     TEXT PRIMARY KEY,
                title        TEXT NOT NULL DEFAULT \'\',
                photographer TEXT NOT NULL DEFAULT \'\',
                tags_csv     TEXT NOT NULL DEFAULT \'\',
                url          TEXT NOT NULL DEFAULT \'\',
                thumb_url    TEXT NOT NULL DEFAULT \'\',
                panel_url    TEXT NOT NULL DEFAULT \'\'
            )'
        );

        $this->database->exec(
            'CREATE INDEX IF NOT EXISTS idx_imagebank_files_photographer'
                . ' ON ' . self::FILES_TABLE . ' (photographer)'
        );

        $this->database->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::TAGS_TABLE . ' (
                filename TEXT NOT NULL,
                tag      TEXT NOT NULL
            )'
        );

        $this->database->exec(
            'CREATE INDEX IF NOT EXISTS idx_imagebank_file_tags_tag'
                . ' ON ' . self::TAGS_TABLE . ' (tag)'
        );

        $this->database->exec(
            'CREATE INDEX IF NOT EXISTS idx_imagebank_file_tags_filename'
                . ' ON ' . self::TAGS_TABLE . ' (filename)'
        );

        $this->database->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::META_TABLE . ' (
                key   TEXT PRIMARY KEY,
                value TEXT
            )'
        );
    }

    /**
     * Apply any schema migrations needed for databases created before the panel
     * tables were introduced.
     */
    private function runMigrations(): void
    {
        // Migration 1: add taxa_id column to imagebank_taxa_map if absent
        $columns = $this->database->query(
            'PRAGMA table_info(' . self::TABLE_NAME . ')'
        )->fetchAll(PDO::FETCH_ASSOC);

        $hasColumn = array_filter($columns, fn(array $c): bool => $c['name'] === 'taxa_id');
        if (empty($hasColumn)) {
            $this->database->exec(
                "ALTER TABLE " . self::TABLE_NAME . " ADD COLUMN taxa_id TEXT NOT NULL DEFAULT ''"
            );
            $this->database->exec(
                'CREATE INDEX IF NOT EXISTS idx_imagebank_taxa_id'
                    . ' ON ' . self::TABLE_NAME . ' (taxa_id)'
            );
        }

        // Migration 2: create panel tables if absent
        $this->database->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::FILES_TABLE . ' (
                filename     TEXT PRIMARY KEY,
                title        TEXT NOT NULL DEFAULT \'\',
                photographer TEXT NOT NULL DEFAULT \'\',
                tags_csv     TEXT NOT NULL DEFAULT \'\',
                url          TEXT NOT NULL DEFAULT \'\',
                thumb_url    TEXT NOT NULL DEFAULT \'\',
                panel_url    TEXT NOT NULL DEFAULT \'\'
            )'
        );

        // Migration 3: add thumb_url to imagebank_files if absent
        $fileColumns = $this->database->query(
            'PRAGMA table_info(' . self::FILES_TABLE . ')'
        )->fetchAll(PDO::FETCH_ASSOC);
        $hasThumbUrl = array_filter($fileColumns, fn(array $c): bool => $c['name'] === 'thumb_url');
        if (empty($hasThumbUrl)) {
            $this->database->exec(
                "ALTER TABLE " . self::FILES_TABLE . " ADD COLUMN thumb_url TEXT NOT NULL DEFAULT ''"
            );
        }

        $this->database->exec(
            'CREATE INDEX IF NOT EXISTS idx_imagebank_files_photographer'
                . ' ON ' . self::FILES_TABLE . ' (photographer)'
        );

        $this->database->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::TAGS_TABLE . ' (
                filename TEXT NOT NULL,
                tag      TEXT NOT NULL
            )'
        );

        $this->database->exec(
            'CREATE INDEX IF NOT EXISTS idx_imagebank_file_tags_tag'
                . ' ON ' . self::TAGS_TABLE . ' (tag)'
        );

        $this->database->exec(
            'CREATE INDEX IF NOT EXISTS idx_imagebank_file_tags_filename'
                . ' ON ' . self::TAGS_TABLE . ' (filename)'
        );
    }
}
