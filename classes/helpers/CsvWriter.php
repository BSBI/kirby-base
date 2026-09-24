<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

/**
 * Builds CSV text for downloads that people open in a spreadsheet.
 *
 * Every cell that could read as a formula (starting = + - @ tab or CR) gets a
 * leading apostrophe, the standard defence against CSV formula injection: the
 * exports carry values visitors typed. fputcsv() gets its separator, enclosure
 * and escape explicitly, as PHP 8.4 requires.
 */
final class CsvWriter
{
    /**
     * @param iterable<array<int, scalar|null>> $rows One array of cells per line.
     * @return string The CSV, one line per row, each ending in "\n".
     */
    public static function toString(iterable $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        foreach ($rows as $row) {
            fputcsv($handle, array_map(self::safeCell(...), array_values($row)), ',', '"', '\\');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv === false ? '' : $csv;
    }

    /**
     * The cell as text, prefixed with an apostrophe if a spreadsheet could read
     * it as a formula.
     *
     * @param scalar|null $value
     * @return string
     */
    public static function safeCell(mixed $value): string
    {
        $text = (string)$value;

        return $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true)
            ? "'" . $text
            : $text;
    }
}
