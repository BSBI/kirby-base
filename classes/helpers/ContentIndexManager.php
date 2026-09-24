<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

use Exception;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Filesystem\Dir;
use Kirby\Filesystem\F;
use PDO;
use Throwable;

/**
 * Manages a single SQLite content index database.
 *
 * Each ContentIndexManager is paired with a ContentIndexDefinition that defines
 * the schema and data extraction logic. The manager handles database creation,
 * page indexing/removal, full rebuilds, and provides a query builder.
 *
 * @package BSBI\WebBase\helpers
 */
class ContentIndexManager
{
    /** @var string Default database directory relative to site root */
    private const string DEFAULT_DATABASE_PATH = '/logs/content-indexes/';

    private ContentIndexDefinition $definition;
    private PDO $database;
    private App $kirby;
    private string $tableName;

    /**
     * @param ContentIndexDefinition $definition The index definition
     * @throws Exception If database cannot be initialized
     */
    public function __construct(ContentIndexDefinition $definition)
    {
        $this->definition = $definition;
        $this->kirby = kirby();
        $this->tableName = 'content_' . $definition->getName();
        $this->initializeDatabase();
    }

    /**
     * Get the definition this manager is built from.
     *
     * @return ContentIndexDefinition
     */
    public function getDefinition(): ContentIndexDefinition
    {
        return $this->definition;
    }

    /**
     * Index (upsert) a single page into the content index.
     *
     * @param Page $page The Kirby page to index
     * @param KirbyBaseHelper $helper Helper instance for field access
     * @return bool True if the page was indexed successfully
     */
    public function indexPage(Page $page, KirbyBaseHelper $helper): bool
    {
        try {
            // Remove existing entry first (handles slug changes and draft transitions)
            $this->removePage($page->id());

            // Only index listed pages — exclude drafts and unlisted pages
            if (!$page->isListed()) {
                return false;
            }

            $rowData = $this->definition->getRowData($page, $helper);

            if (empty($rowData)) {
                return false;
            }

            $columns = array_keys($rowData);
            $placeholders = array_map(fn(string $col) => ':' . $col, $columns);

            $sql = sprintf(
                'INSERT INTO %s (%s) VALUES (%s)',
                $this->tableName,
                implode(', ', $columns),
                implode(', ', $placeholders)
            );

            $stmt = $this->database->prepare($sql);

            foreach ($rowData as $column => $value) {
                $stmt->bindValue(':' . $column, $value);
            }

            return $stmt->execute();
        } catch (Throwable $e) {
            error_log('Content index error indexing page ' . $page->id() . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Remove a page from the content index.
     *
     * @param string $pageId The Kirby page ID to remove
     * @return bool True if deletion was executed
     */
    public function removePage(string $pageId): bool
    {
        $stmt = $this->database->prepare("DELETE FROM {$this->tableName} WHERE page_id = :page_id");
        return $stmt->execute(['page_id' => $pageId]);
    }

    /**
     * Rebuild the entire index from the collection.
     *
     * @param KirbyBaseHelper $helper Helper instance for field access
     * @return int Number of pages indexed
     * @throws Throwable If database transaction fails
     */
    public function rebuildIndex(KirbyBaseHelper $helper): int
    {
        $collectionName = $this->definition->getCollectionName();
        $collection = $this->kirby->collection($collectionName);

        if ($collection === null) {
            throw new Exception(
                "Collection '$collectionName' not found for content index '{$this->definition->getName()}'"
            );
        }

        $this->database->beginTransaction();

        try {
            $this->database->exec("DELETE FROM {$this->tableName}");

            $count = 0;

            /** @var Page $page */
            foreach ($collection as $page) {
                try {
                    // Only index listed pages — skip drafts and unlisted pages
                    if (!$page->isListed()) {
                        continue;
                    }

                    $rowData = $this->definition->getRowData($page, $helper);
                    if (empty($rowData)) {
                        continue;
                    }

                    $columns = array_keys($rowData);
                    $placeholders = array_map(fn(string $col) => ':' . $col, $columns);

                    $sql = sprintf(
                        'INSERT INTO %s (%s) VALUES (%s)',
                        $this->tableName,
                        implode(', ', $columns),
                        implode(', ', $placeholders)
                    );

                    $stmt = $this->database->prepare($sql);

                    foreach ($rowData as $column => $value) {
                        $stmt->bindValue(':' . $column, $value);
                    }

                    $stmt->execute();
                    $count++;
                } catch (Throwable $e) {
                    error_log('Content index rebuild: failed to index page ' . $page->id() . ': ' . $e->getMessage());
                }
            }

            // Update metadata
            $this->database->exec("
                CREATE TABLE IF NOT EXISTS content_index_meta (
                    key TEXT PRIMARY KEY,
                    value TEXT
                )
            ");
            $metaStmt = $this->database->prepare(
                'INSERT OR REPLACE INTO content_index_meta (key, value) VALUES (:key, :value)'
            );
            $metaStmt->execute(['key' => 'last_rebuild', 'value' => date('Y-m-d H:i:s')]);

            $this->database->commit();
        } catch (Throwable $e) {
            $this->database->rollBack();
            throw $e;
        }

        return $count;
    }

    /**
     * Create a new query builder for this index.
     *
     * @return ContentIndexQuery
     */
    public function query(): ContentIndexQuery
    {
        return new ContentIndexQuery($this->database, $this->tableName);
    }

    /**
     * Get statistics about this content index.
     *
     * @return array{name: string, total_rows: int, last_rebuild: string|null}
     */
    public function getStats(): array
    {
        $countStmt = $this->database->query("SELECT COUNT(*) as total FROM {$this->tableName}");
        $count = $countStmt->fetch(PDO::FETCH_ASSOC);

        $lastRebuild = null;
        try {
            $metaStmt = $this->database->prepare('SELECT value FROM content_index_meta WHERE key = :key');
            $metaStmt->execute(['key' => 'last_rebuild']);
            $metaResult = $metaStmt->fetch(PDO::FETCH_ASSOC);
            $lastRebuild = $metaResult['value'] ?? null;
        } catch (Throwable) {
            // Meta table may not exist yet
        }

        return [
            'name' => $this->definition->getName(),
            'total_rows' => (int)($count['total'] ?? 0),
            'last_rebuild' => $lastRebuild,
        ];
    }

    /**
     * Get the database path from configuration.
     *
     * @return string Database directory path relative to site root
     */
    private function getDatabasePath(): string
    {
        return option('contentIndex.databasePath', self::DEFAULT_DATABASE_PATH);
    }

    /**
     * Initialize the database connection, creating file and schema if needed.
     *
     * @throws Exception If database directory cannot be created or connection fails
     */
    private function initializeDatabase(): void
    {
        $dir = SitePaths::resolve($this->kirby, $this->getDatabasePath());
        $file = $dir . $this->definition->getName() . '.sqlite';

        $needsCreate = !F::exists($file);

        if ($needsCreate) {
            if (!is_dir($dir)) {
                if (Dir::make($dir) === false) {
                    throw new Exception("Failed to create content index directory: $dir");
                }
            }
        }

        $this->database = new PDO('sqlite:' . $file);
        $this->database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->database->exec('PRAGMA journal_mode=WAL');

        if ($needsCreate) {
            $this->createSchema();
        } else {
            $this->migrateSchemaIfNeeded();
        }
    }

    /**
     * Ensure the existing table schema matches the definition, adding missing columns.
     *
     * Uses PRAGMA table_info to discover actual columns and compares against the
     * definition. Missing columns are added via ALTER TABLE. If column order or types
     * have changed the table is dropped and recreated (data must be rebuilt).
     */
    private function migrateSchemaIfNeeded(): void
    {
        $definedColumns = $this->definition->getColumns();
        $expectedNames = array_merge(['page_id'], array_keys($definedColumns));

        $stmt = $this->database->query("PRAGMA table_info({$this->tableName})");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            // Table does not exist yet
            $this->createSchema();
            return;
        }

        $actualNames = array_map(fn(array $row) => $row['name'], $rows);

        if ($actualNames === $expectedNames) {
            return;
        }

        // Try to add missing columns non-destructively
        $missingColumns = array_diff($expectedNames, $actualNames);
        $extraColumns = array_diff($actualNames, $expectedNames);

        if (!empty($extraColumns) || empty($missingColumns)) {
            // Columns were removed or reordered — must recreate
            $this->database->exec("DROP TABLE IF EXISTS {$this->tableName}");
            $this->createSchema();
            return;
        }

        // Only additions — safe to ALTER TABLE
        foreach ($missingColumns as $columnName) {
            if ($columnName === 'page_id') {
                continue;
            }
            $type = $definedColumns[$columnName] ?? 'TEXT NOT NULL DEFAULT ""';
            $this->database->exec("ALTER TABLE {$this->tableName} ADD COLUMN {$columnName} {$type}");
        }
    }

    /**
     * Create the database table from the definition's column spec.
     */
    private function createSchema(): void
    {
        $columns = $this->definition->getColumns();

        $columnDefs = ['page_id TEXT PRIMARY KEY'];
        foreach ($columns as $name => $type) {
            if ($name === 'page_id') {
                continue; // Already added as primary key
            }
            $columnDefs[] = "$name $type";
        }

        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (%s)',
            $this->tableName,
            implode(', ', $columnDefs)
        );

        $this->database->exec($sql);

        // Create meta table
        $this->database->exec('
            CREATE TABLE IF NOT EXISTS content_index_meta (
                key TEXT PRIMARY KEY,
                value TEXT
            )
        ');

        // Create indexes
        foreach ($this->definition->getIndexes() as $indexSql) {
            $this->database->exec($indexSql);
        }
    }
}
