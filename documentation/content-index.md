# Content Index System

The content index system provides a high-performance SQLite-backed alternative to Kirby's `site()->index()` for fast querying of large page collections. Instead of loading all Kirby pages into memory, content indexes store normalized data in SQL tables with efficient query support.

## Overview

Content indexes are designed to solve two problems:

1. **Performance**: Kirby's `site()->index()` loads every page's metadata into memory, which becomes prohibitively slow with thousands of pages. Content indexes replace this with SQL queries that return only relevant results.
2. **Indexing patterns**: Different use cases need different data models. Content index definitions allow you to define exactly which pages and fields to index, and what columns to extract.

The system automatically keeps indexes synchronized with Kirby's page lifecycle (create, update, delete, status change). Indexes are stored as SQLite databases in `/logs/content-indexes/` by default.

## Architecture

### Core Components

#### ContentIndexDefinition
`classes/helpers/ContentIndexDefinition.php`

An abstract base class that defines what to index. Extend this class to create a new index. Implementations must provide:

- `getName()` — returns the index name (e.g., `'form_submissions'`), used as the SQLite filename
- `getCollectionName()` — returns the Kirby collection name to index (e.g., `'formSubmissions'`)
- `getTemplates()` — returns an array of template names that trigger reindexing in hooks (e.g., `['form_submission']`)
- `getColumns()` — returns column definitions as `[columnName => sqlType]`, e.g., `['form_type' => 'TEXT NOT NULL DEFAULT ""']`
- `getIndexes()` — returns array of SQL CREATE INDEX statements for optimization
- `getRowData(Page $page, KirbyBaseHelper $helper)` — extracts and returns row data from a page

##### Example: FormSubmissionIndexDefinition

```php
class FormSubmissionIndexDefinition extends ContentIndexDefinition
{
    public function getName(): string { return 'form_submissions'; }
    public function getCollectionName(): string { return 'formSubmissions'; }
    public function getTemplates(): array { return ['form_submission']; }
    public function getColumns(): array {
        return [
            'form_type'    => 'TEXT NOT NULL DEFAULT ""',
            'submitted_at' => 'TEXT NOT NULL DEFAULT ""',
        ];
    }
    public function getIndexes(): array {
        return [
            'CREATE INDEX IF NOT EXISTS idx_form_submissions_form_type'
                . ' ON content_form_submissions (form_type)',
            'CREATE INDEX IF NOT EXISTS idx_form_submissions_submitted_at'
                . ' ON content_form_submissions (submitted_at)',
        ];
    }
    public function getRowData(Page $page, KirbyBaseHelper $helper): array {
        return [
            'page_id'      => $page->id(),
            'form_type'    => $helper->getPageFieldAsString($page, 'form_type'),
            'submitted_at' => $page->modified('Y-m-d H:i:s') ?? date('Y-m-d H:i:s'),
        ];
    }
}
```

#### ContentIndexManager
`classes/helpers/ContentIndexManager.php`

Manages the lifecycle of a single SQLite index database. Created from a ContentIndexDefinition, the manager handles:

- **Database initialization** — creates the SQLite file in `/logs/content-indexes/` if it doesn't exist
- **Schema creation and migration** — creates the table schema from the definition and adds missing columns when definitions change
- **Page indexing** — inserts or updates a single page in the index (`indexPage()`)
- **Page removal** — removes a page from the index (`removePage()`)
- **Full rebuild** — clears and reconstructs the entire index from the collection (`rebuildIndex()`)
- **Query builder creation** — returns a `ContentIndexQuery` instance for building and executing queries
- **Statistics** — returns row counts and last rebuild timestamp

```php
$manager = ContentIndexRegistry::get('form_submissions');
$count = $manager->query()
    ->where('form_type', 'feedback')
    ->count();
```

#### ContentIndexRegistry
`classes/helpers/ContentIndexRegistry.php`

A static registry that holds all registered ContentIndexManager instances. Provides:

- `register(ContentIndexDefinition $definition)` — creates and registers a manager for the definition
- `get(string $name)` — retrieves a manager by name
- `getManagersForTemplate(string $template)` — retrieves all managers that index a given template (used by hooks)
- `all()` — retrieves all managers
- `clear()` — clears all registrations (primarily for testing)

Registrations happen in `index.php` during plugin initialization:

1. Built-in `FormSubmissionIndexDefinition` is registered automatically
2. The plugin fires an `option('contentIndexDefinitions', [])` hook for sites to register custom definitions
3. Any `ContentIndexDefinition` instances in the option array are automatically registered

#### ContentIndexQuery
`classes/helpers/ContentIndexQuery.php`

A fluent query builder for creating SQL SELECT queries against content index tables. Supports:

- **Filtering**: `where()`, `whereOp()`, `whereIn()`, `whereContains()`, `whereContainsAny()`, `whereDateBetween()`, `whereDateOnOrAfter()`, `whereDateBefore()`, `whereTrue()`, `whereNotEmpty()`
- **Ordering**: `orderBy(column, direction)`
- **Pagination**: `limit()`, `offset()`
- **Execution**: `get()` (all rows), `getPageIds()` (page IDs only), `count()` (row count)

All methods return `$this` for fluent chaining. Parameters are automatically escaped.

```php
$results = $manager->query()
    ->where('form_type', 'feedback')
    ->whereDateOnOrAfter('submitted_at', '2025-01-01')
    ->orderBy('submitted_at', 'desc')
    ->limit(50)
    ->get();

$pageIds = $manager->query()
    ->where('form_type', 'event')
    ->getPageIds();
```

## Page Lifecycle Hooks

The system automatically keeps indexes synchronized via hooks in `hooks.php`. The same handler function (`handlePageChange()`) is called by multiple hooks:

- `page.create:after` — adds new pages to their index
- `page.update:after` — updates pages when fields change
- `page.changeTitle:after` — handles title-only changes
- `page.changeSlug:after` — removes stale entries for renamed pages, then reindexes
- `page.changeStatus:after` — reindexes when pages are published/unpublished/drafted
- `page.delete:before` — removes pages from all indexes

For each hook, the system:

1. Determines which templates changed (e.g., `'form_submission'`)
2. Looks up all managers registered for those templates via `ContentIndexRegistry::getManagersForTemplate()`
3. Calls `manager->indexPage()` or `manager->removePage()` for each manager
4. Logs errors without interrupting the page save

Only **listed pages** are indexed. Pages with `isListed() === false` (drafts, unlisted pages) are excluded.

## Registration

### Built-in Registration

The `FormSubmissionIndexDefinition` is automatically registered during plugin initialization:

```php
// In index.php
ContentIndexRegistry::register(new FormSubmissionIndexDefinition());
```

### Custom Registration

Sites can register custom index definitions via the `contentIndexDefinitions` option. In your site's `config/config.php`:

```php
'contentIndexDefinitions' => [
    new \YourNamespace\EventIndexDefinition(),
    new \YourNamespace\PlantIndexDefinition(),
],
```

During plugin initialization, any definitions returned by `option('contentIndexDefinitions', [])` are automatically registered.

## Admin Routes

### Rebuild Indexes

**Route**: `/content-index-rebuild`  
**Method**: GET  
**Parameters**: `name` (optional, index name to rebuild)  
**Authorization**: Admin/editor only

Rebuilds one or all indexes from scratch by clearing the table and re-extracting all rows from the collection.

```bash
# Rebuild all indexes
curl https://example.com/content-index-rebuild

# Rebuild specific index
curl https://example.com/content-index-rebuild?name=form_submissions
```

Returns a plain text response with row counts per index.

### Index Statistics

**Route**: `/content-index-stats`  
**Method**: GET  
**Authorization**: Admin/editor only

Returns JSON with statistics for all registered indexes:

```json
[
    {
        "name": "form_submissions",
        "total_rows": 1543,
        "last_rebuild": "2025-04-30 14:23:45"
    }
]
```

## Panel Integration

### Form Submissions Index Section

**File**: `sections/formsubmissionsindex.php`

A panel section that displays form submission statistics. Included on the site dashboard, it shows:

- Total submission count across all form types
- Per-form-type submission counts (sorted by count, descending)
- Links to export submissions by form type

The section uses the `form_submissions` index for O(1) lookups instead of loading all form_submission pages.

**Props**:
- `headline` (optional, default: `'Form Submissions'`)

**Data** (computed properties):
- `formTypes` — array of objects with `formType`, `count`, and `exportUrl`
- `totalCount` — total submissions across all form types
- `exportAllUrl` — URL to export all submissions

### Form Export Routes

The `form-export-all` route uses the content index to efficiently find matching submissions by form type, then exports them as CSV. See routes.php for implementation details.

## Database Schema

### Table Structure

Each index creates a table named `content_{indexName}`, e.g., `content_form_submissions`. The table includes:

- `page_id TEXT PRIMARY KEY` — the Kirby page ID
- Additional columns defined by the index definition

The `form_submissions` index includes:
```sql
CREATE TABLE content_form_submissions (
    page_id TEXT PRIMARY KEY,
    form_type TEXT NOT NULL DEFAULT "",
    submitted_at TEXT NOT NULL DEFAULT ""
);
```

### Metadata

A `content_index_meta` table stores:
- `last_rebuild` — timestamp of the last full rebuild

### Indexing

The definition's `getIndexes()` method returns CREATE INDEX statements that are applied to optimize queries. The `form_submissions` index includes:
- `idx_form_submissions_form_type` on `form_type`
- `idx_form_submissions_submitted_at` on `submitted_at`

### Storage Location

By default, index files are stored at `site/logs/content-indexes/`. Paths under `/logs/` resolve against Kirby's `logs` root (`SitePaths::resolve()`), which defaults to `site/logs`; a site that sets its own `logs` root (such as a seeded browser-test site) therefore gets its own indexes. The same applies to the search, file-link and image-bank indexes. The path can be configured via the `contentIndex.databasePath` option in `config.php`:

```php
'contentIndex' => [
    'databasePath' => '/custom/path/',  // relative to site root
],
```

## Schema Evolution

When a definition's columns change, the manager automatically:

1. Compares defined columns against existing table schema
2. **If only columns were added**: Uses `ALTER TABLE ADD COLUMN` to safely add missing columns without data loss
3. **If columns were removed or reordered**: Drops and recreates the table, requiring a rebuild

This means schema additions are non-destructive, but removals/reorders require a rebuild. Plan carefully when evolving definitions.

## Error Handling

- Indexing errors are logged to `logs/search-index.log` via `KirbyBaseHelper::writeToLogFile()` but do not interrupt page saves
- Database errors during connection or initialization throw exceptions
- The page lifecycle hooks catch exceptions and log them; pages are still saved even if indexing fails

## Performance Considerations

- **Indexed columns**: Define indexes on columns you'll frequently query. The `form_submissions` index includes `form_type` and `submitted_at`.
- **Page limiting**: Only listed pages are indexed. Use `isListed()` to exclude drafts and unlisted pages.
- **Rebuild timing**: Full rebuilds iterate the entire collection and reconstruct the table. On large sites, rebuild times can be noticeable; consider running them during off-peak hours.
- **Disk space**: SQLite uses WAL (write-ahead logging) for concurrency. Expect some additional disk space for WAL files.

## Testing

The system includes unit tests for:

- `ContentIndexManagerTest.php` — manager behavior, schema migration, indexing, rebuilds
- `ContentIndexQueryTest.php` — query builder filtering, ordering, pagination

Run tests with:
```bash
vendor/bin/phpunit
```

## Creating a Custom Index

### Step 1: Define the Index

Create a class extending `ContentIndexDefinition`:

```php
<?php
namespace App\Indexes;

use BSBI\WebBase\helpers\ContentIndexDefinition;
use Kirby\Cms\Page;
use BSBI\WebBase\helpers\KirbyBaseHelper;

class EventIndexDefinition extends ContentIndexDefinition
{
    public function getName(): string {
        return 'events';
    }

    public function getCollectionName(): string {
        return 'events';
    }

    public function getTemplates(): array {
        return ['event'];
    }

    public function getColumns(): array {
        return [
            'event_date' => 'TEXT NOT NULL DEFAULT ""',
            'location'   => 'TEXT NOT NULL DEFAULT ""',
        ];
    }

    public function getIndexes(): array {
        return [
            'CREATE INDEX IF NOT EXISTS idx_events_event_date'
                . ' ON content_events (event_date)',
        ];
    }

    public function getRowData(Page $page, KirbyBaseHelper $helper): array {
        return [
            'page_id'    => $page->id(),
            'event_date' => $helper->getPageFieldAsString($page, 'event_date'),
            'location'   => $helper->getPageFieldAsString($page, 'location'),
        ];
    }
}
```

### Step 2: Register the Index

In your site's `config/config.php`:

```php
'contentIndexDefinitions' => [
    new \App\Indexes\EventIndexDefinition(),
],
```

### Step 3: Rebuild

Call the rebuild route to populate the index with existing data:

```bash
curl https://example.com/content-index-rebuild?name=events
```

### Step 4: Use in Code

Query the index:

```php
$manager = ContentIndexRegistry::get('events');
$upcomingEvents = $manager->query()
    ->whereDateOnOrAfter('event_date', date('Y-m-d'))
    ->orderBy('event_date', 'asc')
    ->getPageIds();

foreach ($upcomingEvents as $pageId) {
    $event = page($pageId);
    // ...
}
```

## Common Patterns

### Export Filtered Data as CSV

```php
$manager = ContentIndexRegistry::get('form_submissions');
$pageIds = $manager->query()
    ->where('form_type', 'feedback')
    ->getPageIds();

// Load pages and export
foreach ($pageIds as $pageId) {
    $page = page($pageId);
    // process...
}
```

### Count by Category

```php
$manager = ContentIndexRegistry::get('form_submissions');
$allRows = $manager->query()->get();

$counts = [];
foreach ($allRows as $row) {
    $type = $row['form_type'] ?? '(untyped)';
    $counts[$type] = ($counts[$type] ?? 0) + 1;
}
```

### Paginated Results

```php
$pageSize = 50;
$page = (int) kirby()->request()->get('page', 1);
$offset = ($page - 1) * $pageSize;

$results = $manager->query()
    ->where('form_type', 'event')
    ->orderBy('submitted_at', 'desc')
    ->limit($pageSize)
    ->offset($offset)
    ->get();

$total = $manager->query()
    ->where('form_type', 'event')
    ->count();
```
