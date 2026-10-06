<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

use Kirby\Cms\Page;

/**
 * Abstract base class for content index definitions.
 *
 * Site-specific indexes extend this class to define their schema, source collection,
 * and data extraction logic. Each definition corresponds to one SQLite database file.
 *
 * @package BSBI\WebBase\helpers
 */
abstract class ContentIndexDefinition
{
    /**
     * Get the index name, used as the SQLite filename (e.g. 'events').
     *
     * @return string
     */
    abstract public function getName(): string;

    /**
     * Get the Kirby collection name providing pages to index.
     *
     * @return string
     */
    abstract public function getCollectionName(): string;

    /**
     * Get template names that trigger re-indexing in hooks.
     *
     * @return string[]
     */
    abstract public function getTemplates(): array;

    /**
     * Get column definitions for the index table.
     *
     * Returns an associative array of column name => SQL type definition.
     * A 'page_id' column is always added automatically as the primary key.
     *
     * @return array<string, string> e.g. ['title' => 'TEXT NOT NULL DEFAULT ""', 'start_date' => 'TEXT']
     */
    abstract public function getColumns(): array;

    /**
     * Get SQL CREATE INDEX statements for the index table.
     *
     * @return string[] Array of complete CREATE INDEX SQL strings
     */
    abstract public function getIndexes(): array;

    /**
     * Extract row data from a Kirby page for insertion into the index.
     *
     * The returned array keys must match the column names from getColumns().
     * The 'page_id' key should be included with the page's ID.
     *
     * @param Page $page The Kirby page to extract data from
     * @param KirbyBaseHelper $helper Helper instance for field access
     * @return array<string, mixed> Column values keyed by column name
     */
    abstract public function getRowData(Page $page, KirbyBaseHelper $helper): array;

    /**
     * Returns true if the page belongs in the index. By default only listed
     * pages do (drafts and unlisted pages are left out); a definition whose
     * pages are usually unlisted overrides this.
     *
     * @param Page $page A page using one of getTemplates()
     */
    public function shouldIndex(Page $page): bool
    {
        return $page->isListed();
    }
}
