<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

use Kirby\Data\Txt;
use RuntimeException;

/**
 * One-off migration of the page-based search log into SearchLogStore.
 *
 * Reads the raw `content/search-log/*\/search_log_item*.txt` files directly
 * (Kirby's own txt content format) rather than instantiating them as pages —
 * the whole reason this migration exists is that page objects over 49k
 * siblings are what made the old log slow, so the migrator must not recreate
 * that cost.
 *
 * Idempotency is a simple non-empty-table guard: by default `migrate()`
 * refuses to run again once the store has rows, so a re-run cannot silently
 * duplicate the import. `$force` truncates the store first instead.
 */
final readonly class SearchLogMigrator
{
    /**
     * @param SearchLogStore $store The destination store for migrated rows
     */
    public function __construct(private SearchLogStore $store)
    {
    }

    /**
     * Migrate every `search_log_item` content entry found under the given directory.
     *
     * @param string $searchLogContentDir Absolute path to the `search-log` content
     *        folder (each immediate subdirectory is one log entry)
     * @param bool $force When true, deletes all existing rows first and re-imports;
     *        when false (default) and the store already has rows, throws instead.
     * @return array{migrated: int, skipped: int}
     * @throws RuntimeException If the store already has rows and $force is false
     * @throws \Throwable If the transactional import fails
     */
    public function migrate(string $searchLogContentDir, bool $force = false): array
    {
        $existing = $this->store->count();
        if ($existing > 0 && !$force) {
            throw new RuntimeException(
                "search_log already has {$existing} row(s); re-running migrate() would double-count entries. " .
                'Pass $force = true to truncate and re-import.'
            );
        }

        $entries = $this->collectEntries($searchLogContentDir);

        /** @var array{migrated: int, skipped: int} $result */
        $result = $this->store->runInTransaction(function () use ($entries, $force): array {
            if ($force) {
                $this->store->deleteAll();
            }

            $migrated = 0;
            $skipped = 0;

            foreach ($entries as $entry) {
                if ($entry === null) {
                    $skipped++;
                    continue;
                }

                $this->store->insert($entry['query'], $entry['searchedAt']);
                $migrated++;
            }

            return ['migrated' => $migrated, 'skipped' => $skipped];
        });

        return $result;
    }

    /**
     * Scan each entry subdirectory and parse it into a query/date pair, or
     * null when the entry should be skipped (no usable query, or no usable date).
     *
     * @param string $dir Absolute path to the search-log content directory
     * @return list<array{query: string, searchedAt: string}|null>
     */
    private function collectEntries(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $entries = [];
        $names = scandir($dir) ?: [];
        sort($names);

        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $entryDir = $dir . '/' . $name;
            if (!is_dir($entryDir)) {
                continue;
            }

            $entries[] = $this->parseEntry($entryDir, $name);
        }

        return $entries;
    }

    /**
     * Parse a single `search_log_item*.txt` file (there may be more than one
     * language variant present; exactly one is read, so the same folder is
     * never inserted twice) into a query/date pair.
     *
     * @param string $entryDir Absolute path to the entry folder
     * @param string $folderName The folder's basename, used as a date fallback
     * @return array{query: string, searchedAt: string}|null Null when the entry
     *         has no usable query, or no usable date
     */
    private function parseEntry(string $entryDir, string $folderName): ?array
    {
        $files = glob($entryDir . '/search_log_item*.txt') ?: [];
        if (empty($files)) {
            return null;
        }

        sort($files);
        $file = $files[0];

        $contents = file_get_contents($file);
        if ($contents === false) {
            return null;
        }

        $data = Txt::decode($contents);

        $query = trim((string) ($data['searchquery'] ?? ''));
        if ($query === '') {
            return null;
        }

        $date = trim((string) ($data['searchdate'] ?? ''));
        if ($date === '') {
            $date = $this->dateFromFolderName($folderName) ?? '';
        }

        if ($date === '') {
            return null;
        }

        return ['query' => $query, 'searchedAt' => $date];
    }

    /**
     * Parse a `N_YYYY-MM-DD-HH-MM-SS` entry folder name into a `Y-m-d H:i:s` date.
     *
     * @param string $folderName The entry folder's basename
     * @return string|null The parsed date, or null if the name does not match
     */
    private function dateFromFolderName(string $folderName): ?string
    {
        if (
            preg_match(
                '/^\d+_(\d{4}-\d{2}-\d{2})-(\d{2})-(\d{2})-(\d{2})$/',
                $folderName,
                $matches
            ) !== 1
        ) {
            return null;
        }

        return $matches[1] . ' ' . $matches[2] . ':' . $matches[3] . ':' . $matches[4];
    }
}
