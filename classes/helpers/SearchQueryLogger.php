<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

/**
 * Writes search queries to the SQLite-backed search log.
 *
 * The logger is the config kill-switch for search logging: when constructed
 * with `enabled = false` (from `option('search.logQueries')`), logging is a
 * no-op. All collaborators are injected; no global state is used.
 *
 * Replaces the earlier page-based logger, which created a `search_log_item`
 * content page per search — an O(n) write against a directory that had grown
 * to tens of thousands of siblings. A SQLite insert is O(1) regardless of log
 * size.
 *
 * Throwables from the store are deliberately allowed to propagate — the
 * caller decides how logging failures are reported.
 */
final readonly class SearchQueryLogger
{
    /**
     * Maximum stored query length, in characters. Search queries are free text
     * typed by visitors with no client-side length limit; without a cap here, a
     * handful of repeated max-length queries could bloat the log file for no
     * analytical benefit — nothing meaningful is lost by truncating, since top
     * terms/keywords are short words and phrases anyway.
     */
    private const int MAX_QUERY_LENGTH = 1000;

    /**
     * @param SearchLogStore $store The store to write log entries to
     * @param bool $enabled Whether search logging is enabled (the kill-switch)
     * @param int $retentionMonths Rows older than this are purged on every log()
     *        call; 0 disables the purge (keep forever)
     */
    public function __construct(
        private SearchLogStore $store,
        private bool $enabled,
        private int $retentionMonths,
    ) {
    }

    /**
     * Logs a search query, then purges rows older than the retention window.
     *
     * Returns false without writing anything when logging is disabled. The
     * query is truncated to {@see self::MAX_QUERY_LENGTH} characters before
     * being stored.
     *
     * @param string $query The search query to log
     * @return bool True when a log entry was written, false otherwise
     * @throws \Throwable If the insert or purge fails
     */
    public function log(string $query): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $query = mb_substr($query, 0, self::MAX_QUERY_LENGTH);

        $now = date('Y-m-d H:i:s');
        $this->store->insert($query, $now);

        if ($this->retentionMonths > 0) {
            $cutoffTimestamp = strtotime("-{$this->retentionMonths} months");
            if ($cutoffTimestamp !== false) {
                $this->store->purgeOlderThan(date('Y-m-d H:i:s', $cutoffTimestamp));
            }
        }

        return true;
    }
}
