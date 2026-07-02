<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

use PDO;
use PDOStatement;

/**
 * Fluent query builder for content index SQLite tables.
 *
 * Replaces Kirby's filterBy/sortBy chains with SQL queries for fast listing/filtering.
 * All filter methods return $this for fluent chaining.
 *
 * @package BSBI\WebBase\helpers
 */
class ContentIndexQuery
{
    private PDO $database;
    private string $table;

    /** @var string[] */
    private array $whereClauses = [];

    /** @var array<string, mixed> */
    private array $params = [];

    /** @var string[] */
    private array $orderClauses = [];

    private ?int $limitValue = null;
    private ?int $offsetValue = null;

    private int $paramCounter = 0;

    /**
     * @param PDO $database The SQLite database connection
     * @param string $table The table name to query
     */
    public function __construct(PDO $database, string $table)
    {
        $this->database = $database;
        $this->table = $table;
    }

    /**
     * Add an equality WHERE clause.
     *
     * @param string $column Column name
     * @param string $value Value to match
     * @return $this
     */
    public function where(string $column, string $value): static
    {
        $param = $this->nextParam($column);
        $this->whereClauses[] = "$column = $param";
        $this->params[$param] = $value;
        return $this;
    }

    /**
     * Add a comparison WHERE clause.
     *
     * @param string $column Column name
     * @param string $operator Comparison operator (<, >, <=, >=, !=, <>)
     * @param string $value Value to compare against
     * @return $this
     * @throws \InvalidArgumentException If the operator is not allowed
     */
    public function whereOp(string $column, string $operator, string $value): static
    {
        $allowed = ['<', '>', '<=', '>=', '!=', '<>'];
        if (!in_array($operator, $allowed, true)) {
            throw new \InvalidArgumentException(
                "Operator '$operator' is not allowed. Use one of: " . implode(', ', $allowed)
            );
        }
        $param = $this->nextParam($column);
        $this->whereClauses[] = "$column $operator $param";
        $this->params[$param] = $value;
        return $this;
    }

    /**
     * Filter rows where a comma-separated column contains a specific value.
     *
     * Uses LIKE with boundary awareness: matches the value at start, end,
     * middle (surrounded by commas), or as the entire field.
     *
     * @param string $column Column name containing comma-separated values
     * @param string $value Value to search for within the comma-separated list
     * @return $this
     */
    public function whereContains(string $column, string $value): static
    {
        $param = $this->nextParam($column);
        $paramStart = $this->nextParam($column . '_start');
        $paramEnd = $this->nextParam($column . '_end');
        $paramMid = $this->nextParam($column . '_mid');

        $this->whereClauses[] = "($column = $param OR $column LIKE $paramStart"
            . " OR $column LIKE $paramEnd OR $column LIKE $paramMid)";
        $this->params[$param] = $value;
        $this->params[$paramStart] = "$value,%";
        $this->params[$paramEnd] = "%,$value";
        $this->params[$paramMid] = "%,$value,%";
        return $this;
    }

    /**
     * Filter rows where a comma-separated column contains any of the given values.
     *
     * @param string $column Column name containing comma-separated values
     * @param string[] $values Values to search for (OR logic)
     * @return $this
     */
    public function whereContainsAny(string $column, array $values): static
    {
        if (empty($values)) {
            return $this;
        }

        $valueClauses = [];
        foreach ($values as $value) {
            $value = trim($value);
            if (empty($value)) {
                continue;
            }
            $param = $this->nextParam($column);
            $paramStart = $this->nextParam($column . '_start');
            $paramEnd = $this->nextParam($column . '_end');
            $paramMid = $this->nextParam($column . '_mid');

            $valueClauses[] = "($column = $param OR $column LIKE $paramStart"
                . " OR $column LIKE $paramEnd OR $column LIKE $paramMid)";
            $this->params[$param] = $value;
            $this->params[$paramStart] = "$value,%";
            $this->params[$paramEnd] = "%,$value";
            $this->params[$paramMid] = "%,$value,%";
        }

        if (!empty($valueClauses)) {
            $this->whereClauses[] = '(' . implode(' OR ', $valueClauses) . ')';
        }

        return $this;
    }

    /**
     * Filter rows where any of the given columns starts with the given prefix.
     *
     * Case-insensitive prefix match (SQL LIKE 'prefix%') across one or more columns,
     * combined with OR. Suitable for typeahead/autocomplete lookups. Any LIKE
     * wildcards (%, _) or the escape character (\) in the prefix are escaped so they
     * are matched literally rather than acting as wildcards.
     *
     * Returns $this unchanged if $columns is empty or the trimmed prefix is empty,
     * so an empty search box simply returns all rows (no filter applied).
     *
     * @param string[] $columns Column names to test (OR logic between them)
     * @param string $prefix The prefix to match at the start of each column
     * @return $this
     */
    public function whereAnyStartsWith(array $columns, string $prefix): static
    {
        $prefix = trim($prefix);
        if (empty($columns) || $prefix === '') {
            return $this;
        }

        // Escape the escape char first, then the LIKE wildcards, so a literal % or _
        // in the prefix matches that character rather than acting as a wildcard.
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix);
        $like = $escaped . '%';

        $clauses = [];
        foreach ($columns as $column) {
            $param = $this->nextParam($column . '_prefix');
            $clauses[] = "$column LIKE $param ESCAPE '\\'";
            $this->params[$param] = $like;
        }

        $this->whereClauses[] = '(' . implode(' OR ', $clauses) . ')';
        return $this;
    }

    /**
     * Filter rows where any of the given columns contains a word starting with the prefix.
     *
     * Like whereAnyStartsWith, but matches the prefix at the start of any word within
     * the value, not just the very start of the field — so "Ivy" matches "Common Ivy".
     * A word boundary is the start of the field or a preceding space. Case-insensitive
     * (SQL LIKE); LIKE wildcards (%, _) and the escape character (\) in the prefix are
     * escaped so they match literally. Ideal for common/vernacular-name typeahead.
     *
     * Returns $this unchanged if $columns is empty or the trimmed prefix is empty.
     *
     * @param string[] $columns Column names to test (OR logic between them)
     * @param string $prefix The prefix to match at the start of any word
     * @return $this
     */
    public function whereAnyWordStartsWith(array $columns, string $prefix): static
    {
        $prefix = trim($prefix);
        if (empty($columns) || $prefix === '') {
            return $this;
        }

        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix);
        $atStart = $escaped . '%';        // field begins with the prefix
        $afterSpace = '% ' . $escaped . '%'; // a later word begins with the prefix

        $clauses = [];
        foreach ($columns as $column) {
            $pStart = $this->nextParam($column . '_wordstart');
            $pWord = $this->nextParam($column . '_word');
            $clauses[] = "($column LIKE $pStart ESCAPE '\\' OR $column LIKE $pWord ESCAPE '\\')";
            $this->params[$pStart] = $atStart;
            $this->params[$pWord] = $afterSpace;
        }

        $this->whereClauses[] = '(' . implode(' OR ', $clauses) . ')';
        return $this;
    }

    /**
     * Filter rows where a column value matches any value in the given list (SQL IN).
     *
     * Returns $this unchanged if $values is empty, so callers do not need to guard
     * against empty arrays — the query will simply return all rows.
     *
     * @param string $column Column name
     * @param string[] $values List of values to match against
     * @return $this
     */
    public function whereIn(string $column, array $values): static
    {
        if (empty($values)) {
            return $this;
        }

        $placeholders = [];
        foreach ($values as $value) {
            $param = $this->nextParam($column);
            $placeholders[] = $param;
            $this->params[$param] = $value;
        }

        $this->whereClauses[] = "$column IN (" . implode(', ', $placeholders) . ")";
        return $this;
    }

    /**
     * Filter rows where a date column falls between two dates (inclusive).
     *
     * @param string $column Column name containing date values (Y-m-d format)
     * @param string $start Start date (Y-m-d)
     * @param string $end End date (Y-m-d)
     * @return $this
     */
    public function whereDateBetween(string $column, string $start, string $end): static
    {
        $paramStart = $this->nextParam($column . '_from');
        $paramEnd = $this->nextParam($column . '_to');
        $this->whereClauses[] = "$column >= $paramStart AND $column <= $paramEnd";
        $this->params[$paramStart] = $start;
        $this->params[$paramEnd] = $end;
        return $this;
    }

    /**
     * Filter rows where a date column is on or after a given date.
     *
     * @param string $column Column name containing date values (Y-m-d format)
     * @param string $date Date (Y-m-d)
     * @return $this
     */
    public function whereDateOnOrAfter(string $column, string $date): static
    {
        $param = $this->nextParam($column);
        $this->whereClauses[] = "$column >= $param";
        $this->params[$param] = $date;
        return $this;
    }

    /**
     * Filter rows where a date column is before a given date.
     *
     * @param string $column Column name containing date values (Y-m-d format)
     * @param string $date Date (Y-m-d)
     * @return $this
     */
    public function whereDateBefore(string $column, string $date): static
    {
        $param = $this->nextParam($column);
        $this->whereClauses[] = "$column < $param";
        $this->params[$param] = $date;
        return $this;
    }

    /**
     * Filter rows where a boolean/integer column is truthy (= 1).
     *
     * @param string $column Column name
     * @return $this
     */
    public function whereTrue(string $column): static
    {
        $this->whereClauses[] = "$column = 1";
        return $this;
    }

    /**
     * Filter rows where a boolean/integer column is falsy (= 0).
     *
     * @param string $column Column name
     * @return $this
     */
    public function whereFalse(string $column): static
    {
        $this->whereClauses[] = "$column = 0";
        return $this;
    }

    /**
     * Filter rows where a column is not empty.
     *
     * @param string $column Column name
     * @return $this
     */
    public function whereNotEmpty(string $column): static
    {
        $this->whereClauses[] = "$column IS NOT NULL AND $column != ''";
        return $this;
    }

    /**
     * Filter rows where two TEXT columns (storing numeric values) fall within a lat/lon
     * bounding box. Uses CAST(column AS REAL) so string-stored coordinates compare correctly,
     * including negative values. Intended as a fast pre-filter before an exact haversine check.
     *
     * @param string $latCol  Column name holding latitude values
     * @param string $lonCol  Column name holding longitude values
     * @param float  $minLat  Minimum latitude (inclusive)
     * @param float  $maxLat  Maximum latitude (inclusive)
     * @param float  $minLon  Minimum longitude (inclusive)
     * @param float  $maxLon  Maximum longitude (inclusive)
     * @return $this
     */
    public function whereWithinBoundingBox(
        string $latCol,
        string $lonCol,
        float $minLat,
        float $maxLat,
        float $minLon,
        float $maxLon
    ): static {
        $pMinLat = $this->nextParam($latCol . '_min');
        $pMaxLat = $this->nextParam($latCol . '_max');
        $pMinLon = $this->nextParam($lonCol . '_min');
        $pMaxLon = $this->nextParam($lonCol . '_max');

        $this->whereClauses[] = "CAST($latCol AS REAL) >= $pMinLat"
            . " AND CAST($latCol AS REAL) <= $pMaxLat"
            . " AND CAST($lonCol AS REAL) >= $pMinLon"
            . " AND CAST($lonCol AS REAL) <= $pMaxLon";

        $this->params[$pMinLat] = $minLat;
        $this->params[$pMaxLat] = $maxLat;
        $this->params[$pMinLon] = $minLon;
        $this->params[$pMaxLon] = $maxLon;

        return $this;
    }

    /**
     * Add an ORDER BY clause.
     *
     * @param string $column Column name
     * @param string $direction 'asc' or 'desc'
     * @return $this
     */
    public function orderBy(string $column, string $direction = 'asc'): static
    {
        $dir = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        $this->orderClauses[] = "$column $dir";
        return $this;
    }

    /**
     * Set the maximum number of rows to return.
     *
     * @param int $limit Maximum number of rows
     * @return $this
     */
    public function limit(int $limit): static
    {
        $this->limitValue = $limit;
        return $this;
    }

    /**
     * Set the offset for pagination.
     *
     * @param int $offset Number of rows to skip
     * @return $this
     */
    public function offset(int $offset): static
    {
        $this->offsetValue = $offset;
        return $this;
    }

    /**
     * Execute the query and return all matching rows.
     *
     * @return array<int, array<string, mixed>> Array of associative arrays
     */
    public function get(): array
    {
        $stmt = $this->buildAndExecute('*');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Execute the query and return just page_id values.
     *
     * @return string[] Array of page IDs
     */
    public function getPageIds(): array
    {
        $stmt = $this->buildAndExecute('page_id');
        return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    }

    /**
     * Execute a COUNT query matching the current filters.
     *
     * @return int Number of matching rows
     */
    public function count(): int
    {
        $stmt = $this->buildAndExecute('COUNT(*) as cnt', false);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($result['cnt'] ?? 0);
    }

    /**
     * Build and execute the SQL statement.
     *
     * @param string $select The SELECT expression
     * @param bool $applyLimitAndOrder Whether to apply ORDER BY, LIMIT, OFFSET
     * @return PDOStatement The executed statement
     */
    private function buildAndExecute(string $select, bool $applyLimitAndOrder = true): PDOStatement
    {
        $sql = "SELECT $select FROM {$this->table}";

        if (!empty($this->whereClauses)) {
            $sql .= ' WHERE ' . implode(' AND ', $this->whereClauses);
        }

        if ($applyLimitAndOrder) {
            if (!empty($this->orderClauses)) {
                $sql .= ' ORDER BY ' . implode(', ', $this->orderClauses);
            }

            if ($this->limitValue !== null) {
                $sql .= ' LIMIT ' . $this->limitValue;
            }

            if ($this->offsetValue !== null) {
                $sql .= ' OFFSET ' . $this->offsetValue;
            }
        }

        $stmt = $this->database->prepare($sql);

        foreach ($this->params as $param => $value) {
            $stmt->bindValue($param, $value);
        }

        $stmt->execute();
        return $stmt;
    }

    /**
     * Generate a unique parameter name.
     *
     * @param string $prefix Parameter name prefix
     * @return string The unique parameter placeholder
     */
    private function nextParam(string $prefix): string
    {
        $this->paramCounter++;
        // Sanitise prefix to alphanumeric + underscore only
        $safe = preg_replace('/[^a-zA-Z0-9_]/', '_', $prefix);
        return ':' . $safe . '_' . $this->paramCounter;
    }
}
