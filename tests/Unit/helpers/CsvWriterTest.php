<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\CsvWriter;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CsvWriter (bsbi-web#697).
 *
 * The form-submission export called fputcsv() without its $escape argument,
 * which PHP 8.4 deprecates (a fatal error under Whoops with debug on), and
 * wrote cells untouched, so a submitted value starting with = + - @ would run
 * as a formula when the export is opened in a spreadsheet.
 */
final class CsvWriterTest extends TestCase
{
    public function testRowsBecomeCsvLines(): void
    {
        $this->assertSame(
            "Submission,\"Name, full\",Answer\n\"Vote: 101\",\"O'Brien, Ann\",Yes\n",
            CsvWriter::toString([
                ['Submission', 'Name, full', 'Answer'],
                ['Vote: 101', "O'Brien, Ann", 'Yes'],
            ])
        );
    }

    public function testQuotesAreDoubled(): void
    {
        $this->assertSame("\"Say \"\"hi\"\"\"\n", CsvWriter::toString([['Say "hi"']]));
    }

    public function testFormulaCellsAreNeutralised(): void
    {
        $csv = CsvWriter::toString([['=HYPERLINK("http://x")', '+1', '-2', '@SUM(A1)', "\tx", 'plain']]);

        $this->assertSame(
            "\"'=HYPERLINK(\"\"http://x\"\")\",'+1,'-2,'@SUM(A1),\"'\tx\",plain\n",
            $csv
        );
    }

    public function testEmptyRowsAndCellsAreKept(): void
    {
        $this->assertSame("a,,b\n", CsvWriter::toString([['a', '', 'b']]));
        $this->assertSame('', CsvWriter::toString([]));
    }

    public function testNoDeprecationIsRaised(): void
    {
        $raised = [];
        set_error_handler(static function (int $level, string $message) use (&$raised): bool {
            $raised[] = $message;
            return true;
        });
        try {
            CsvWriter::toString([['x']]);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $raised);
    }
}
