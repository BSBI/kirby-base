<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

use Kirby\Exception\InvalidArgumentException;
use PDO;
use Throwable;

/**
 * SQLite-backed store for the search query log.
 *
 * Replaces the previous page-based log (a `search_log_item` content page per
 * search) with a single `search_log` table: an O(1) insert per search instead
 * of a full sibling scan. Lives in its own database file, separate from the
 * search index (`search.sqlite`) — the index is regenerable from content, the
 * log is not, and mixing authoritative data into a file whose whole purpose
 * is "safe to delete and rebuild" is a trap for whoever next clears it.
 *
 * The connection is injected so the class can be exercised in tests against
 * an in-memory database with no Kirby App and no file I/O; {@see self::open()}
 * is the production entry point that handles the file/directory/WAL concerns.
 */
final readonly class SearchLogStore
{
    /**
     * @param PDO $pdo An open PDO SQLite connection. The constructor ensures
     *        the `search_log` schema exists on it before returning.
     */
    public function __construct(private PDO $pdo)
    {
        $this->ensureSchema();
    }

    /**
     * Open (creating if necessary) the SQLite search log database at the given path.
     *
     * Creates the parent directory if it does not exist, opens the connection
     * in WAL mode (so a write never blocks a read), sets a busy timeout so a
     * write that collides with another connection retries for up to 3s instead
     * of failing immediately with "database is locked", and ensures the schema
     * is present.
     *
     * Rejects any path containing `..` before touching the filesystem or PDO.
     * `$absolutePath` is built from `search.logDatabasePath`, which is admin
     * config rather than end-user input, but a traversal value would still
     * make this method `mkdir` and write outside the intended logs directory.
     *
     * @param string $absolutePath Absolute filesystem path to the database file
     * @throws InvalidArgumentException If the path contains a `..` segment
     */
    public static function open(string $absolutePath): self
    {
        if (str_contains($absolutePath, '..')) {
            throw new InvalidArgumentException(
                "Refusing to open a search log database at a path containing '..': {$absolutePath}"
            );
        }

        $dir = dirname($absolutePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $pdo = new PDO('sqlite:' . $absolutePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA busy_timeout=3000');

        return new self($pdo);
    }

    /**
     * Create the search_log table and its indexes if they do not already exist.
     *
     * Idempotent: safe to call on every construction, whether the database is
     * brand new or an established file.
     */
    private function ensureSchema(): void
    {
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS search_log (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                query       TEXT NOT NULL,
                searched_at TEXT NOT NULL
            )
        ');
        $this->pdo->exec(
            'CREATE INDEX IF NOT EXISTS search_log_searched_at ON search_log (searched_at)'
        );
        $this->pdo->exec(
            'CREATE INDEX IF NOT EXISTS search_log_query ON search_log (query COLLATE NOCASE)'
        );
    }

    /**
     * Insert one search-query event.
     *
     * @param string $query The raw search query as typed
     * @param string $searchedAt Timestamp of the search, `Y-m-d H:i:s`
     */
    public function insert(string $query, string $searchedAt): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO search_log (query, searched_at) VALUES (:query, :searched_at)'
        );
        $stmt->execute(['query' => $query, 'searched_at' => $searchedAt]);
    }

    /**
     * Delete every row logged before the given cutoff (retention purge).
     *
     * @param string $cutoff Rows with `searched_at` earlier than this are removed
     * @return int Number of rows deleted
     */
    public function purgeOlderThan(string $cutoff): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM search_log WHERE searched_at < :cutoff');
        $stmt->execute(['cutoff' => $cutoff]);

        return $stmt->rowCount();
    }

    /**
     * Delete every row in the log.
     *
     * Used by the migrator's `--force` re-import path so a forced re-run
     * cannot double-count rows left over from a previous import.
     *
     * @return int Number of rows deleted
     */
    public function deleteAll(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM search_log');
        $count = (int) ($stmt !== false ? $stmt->fetchColumn() : 0);

        $this->pdo->exec('DELETE FROM search_log');

        return $count;
    }

    /**
     * Total number of rows in the log.
     */
    public function count(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM search_log');

        return (int) ($stmt !== false ? $stmt->fetchColumn() : 0);
    }

    /**
     * Get the most frequently searched terms, case-folded to lower case.
     *
     * @param int $limit Maximum number of terms to return
     * @return array<array{term: string, count: int}>
     */
    public function topTerms(int $limit): array
    {
        $stmt = $this->pdo->prepare('
            SELECT LOWER(query) AS term, COUNT(*) AS count
            FROM search_log
            GROUP BY LOWER(query)
            ORDER BY COUNT(*) DESC, term ASC
            LIMIT :limit
        ');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[] = ['term' => (string) $row['term'], 'count' => (int) $row['count']];
        }

        return $result;
    }

    /**
     * Get every distinct (case-folded) query with its occurrence count.
     *
     * Intended for callers that need to tokenise each query themselves (e.g.
     * keyword extraction) rather than the pre-aggregated {@see self::topTerms()}.
     *
     * @return array<string, int> Lowercased query => occurrence count
     */
    public function queryCounts(): array
    {
        $stmt = $this->pdo->query('
            SELECT LOWER(query) AS term, COUNT(*) AS count
            FROM search_log
            GROUP BY LOWER(query)
        ');

        $result = [];
        foreach (($stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
            $result[(string) $row['term']] = (int) $row['count'];
        }

        return $result;
    }

    /**
     * Summary statistics for the analytics panel.
     *
     * @return array{totalSearches: int, uniqueTerms: int, dateRange: array{from: string|null, to: string|null}}
     */
    public function summary(): array
    {
        $totalSearches = $this->count();

        $uniqueStmt = $this->pdo->query('SELECT COUNT(DISTINCT LOWER(query)) FROM search_log');
        $uniqueTerms = (int) ($uniqueStmt !== false ? $uniqueStmt->fetchColumn() : 0);

        $rangeStmt = $this->pdo->query(
            'SELECT MIN(searched_at) AS from_date, MAX(searched_at) AS to_date FROM search_log'
        );
        $range = $rangeStmt !== false ? $rangeStmt->fetch(PDO::FETCH_ASSOC) : false;

        $from = null;
        $to = null;
        if (is_array($range)) {
            $from = is_string($range['from_date'] ?? null) ? $range['from_date'] : null;
            $to = is_string($range['to_date'] ?? null) ? $range['to_date'] : null;
        }

        return [
            'totalSearches' => $totalSearches,
            'uniqueTerms' => $uniqueTerms,
            'dateRange' => [
                'from' => $from,
                'to' => $to,
            ],
        ];
    }

    /**
     * Run a callback inside a database transaction, committing on success and
     * rolling back if it throws.
     *
     * @param callable $fn Callback to run inside the transaction
     * @return mixed Whatever the callback returns
     * @throws Throwable Whatever the callback throws, after rolling back
     */
    public function runInTransaction(callable $fn): mixed
    {
        $this->pdo->beginTransaction();

        try {
            $result = $fn();
            $this->pdo->commit();

            return $result;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
