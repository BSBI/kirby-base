<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers\maintenance;

use DateTimeImmutable;

/**
 * Pure, filesystem-free log-retention decision logic.
 *
 * kirby-base owns the log line format written by
 * {@see \BSBI\WebBase\helpers\KirbyBaseHelper::writeToLogFile()}:
 * `(new DateTime())->format("y:m:d h:i:s")` — e.g. `26:07:21 09:30:45` = `YY:MM:DD hh:ii:ss`.
 * Only the leading `YY:MM:DD` is read; the time-of-day is ignored.
 *
 * Retention is **entry-aware**: the date sits only on the first line of each entry, and
 * multi-line entries (stack traces) have undated continuation lines that belong to the
 * entry above them. Files with no dated entries at all cannot be date-pruned, so they
 * fall back to a size cap.
 *
 * @package BSBI\WebBase\helpers\maintenance
 */
final readonly class LogRetentionPolicy
{
    /** A line that starts a new entry: `YY:MM:DD ` (date, then a space before the time). */
    private const string ENTRY_START_PATTERN = '/^(\d{2}):(\d{2}):(\d{2}) /';

    /**
     * @param DateTimeImmutable $now the reference "now" (injected so the day-boundary is testable)
     */
    public function __construct(private DateTimeImmutable $now)
    {
    }

    /**
     * Decide how a single log file's content should be rewritten.
     *
     * @param string $content the raw file content
     * @param int $retentionDays keep entries dated on or after (now − this many days)
     * @param int $sizeCapBytes for undated files, keep at most this many trailing bytes
     * @return LogRewriteResult the rewritten content and before/after statistics
     */
    public function apply(string $content, int $retentionDays, int $sizeCapBytes): LogRewriteResult
    {
        $originalBytes = strlen($content);
        $originalLineCount = $this->countLines($content);

        if ($content === '') {
            return new LogRewriteResult('', LogRewriteResult::MODE_EMPTY, 0, 0, 0, 0);
        }

        $hadTrailingNewline = str_ends_with($content, "\n");
        $lines = explode("\n", $content);
        if ($hadTrailingNewline) {
            array_pop($lines); // drop the empty element the trailing newline produced
        }

        /** @var array<int, string> $preamble leading undated lines (kept verbatim) */
        $preamble = [];
        /** @var array<int, array{date: DateTimeImmutable|null, lines: array<int, string>}> $entries */
        $entries = [];
        $current = null;

        foreach ($lines as $line) {
            if (preg_match(self::ENTRY_START_PATTERN, $line, $match) === 1) {
                if ($current !== null) {
                    $entries[] = $current;
                }
                $current = ['date' => $this->parseDate($match[1], $match[2], $match[3]), 'lines' => [$line]];
                continue;
            }

            if ($current === null) {
                $preamble[] = $line;
            } else {
                $current['lines'][] = $line;
            }
        }

        if ($current !== null) {
            $entries[] = $current;
        }

        // No dated entries anywhere → size-cap fallback.
        if ($entries === []) {
            return $this->sizeCap($content, $sizeCapBytes, $originalBytes, $originalLineCount);
        }

        $cutoff = $this->now->setTime(0, 0)->modify('-' . $retentionDays . ' days');

        $kept = $preamble;
        foreach ($entries as $entry) {
            // An entry whose date could not be parsed is kept (can't safely age it out).
            if ($entry['date'] === null || $entry['date'] >= $cutoff) {
                foreach ($entry['lines'] as $entryLine) {
                    $kept[] = $entryLine;
                }
            }
        }

        $newContent = implode("\n", $kept);
        if ($hadTrailingNewline && $newContent !== '') {
            $newContent .= "\n";
        }

        return new LogRewriteResult(
            $newContent,
            LogRewriteResult::MODE_DATE,
            $originalBytes,
            strlen($newContent),
            $originalLineCount,
            $this->countLines($newContent),
        );
    }

    /**
     * Keep only the last $capBytes bytes, trimmed forward to the next line boundary so no
     * partial line survives. Files already within the cap are returned unchanged.
     *
     * @param string $content the raw content
     * @param int $capBytes the maximum number of bytes to keep
     * @param int $originalBytes precomputed byte length of $content
     * @param int $originalLineCount precomputed line count of $content
     * @return LogRewriteResult
     */
    private function sizeCap(string $content, int $capBytes, int $originalBytes, int $originalLineCount): LogRewriteResult
    {
        if ($originalBytes <= $capBytes) {
            return new LogRewriteResult(
                $content,
                LogRewriteResult::MODE_UNCHANGED,
                $originalBytes,
                $originalBytes,
                $originalLineCount,
                $originalLineCount,
            );
        }

        $tail = substr($content, -$capBytes);

        // Drop a leading partial line so the kept content starts cleanly.
        $firstNewline = strpos($tail, "\n");
        if ($firstNewline !== false) {
            $tail = substr($tail, $firstNewline + 1);
        }

        return new LogRewriteResult(
            $tail,
            LogRewriteResult::MODE_SIZE_CAP,
            $originalBytes,
            strlen($tail),
            $originalLineCount,
            $this->countLines($tail),
        );
    }

    /**
     * Parse a `YY:MM:DD` date at midnight, or null if it is not a valid calendar date.
     *
     * @param string $yy two-digit year (2000-based)
     * @param string $mm two-digit month
     * @param string $dd two-digit day
     * @return DateTimeImmutable|null
     */
    private function parseDate(string $yy, string $mm, string $dd): ?DateTimeImmutable
    {
        // `!` resets time to 00:00:00; timezone matches $now for a like-for-like comparison.
        $date = DateTimeImmutable::createFromFormat(
            '!y:m:d',
            $yy . ':' . $mm . ':' . $dd,
            $this->now->getTimezone(),
        );

        if ($date === false) {
            return null;
        }

        // Reject values PHP silently rolls over (e.g. month 13, day 32).
        if ($date->format('y:m:d') !== $yy . ':' . $mm . ':' . $dd) {
            return null;
        }

        return $date;
    }

    /**
     * Count logical lines (a trailing newline does not add an empty line).
     *
     * @param string $content the content to measure
     * @return int
     */
    private function countLines(string $content): int
    {
        if ($content === '') {
            return 0;
        }

        $count = substr_count($content, "\n");
        return str_ends_with($content, "\n") ? $count : $count + 1;
    }
}
