<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers\maintenance;

use BSBI\WebBase\helpers\maintenance\LogRetentionPolicy;
use BSBI\WebBase\helpers\maintenance\LogRewriteResult;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure log-retention decision logic — the highest-risk piece, so it
 * is tested against fixture content with a fixed "now".
 */
final class LogRetentionPolicyTest extends TestCase
{
    private const int RETENTION_DAYS = 30;
    private const int SIZE_CAP = 256; // small cap so fixtures stay readable

    private LogRetentionPolicy $policy;

    protected function setUp(): void
    {
        // Fixed clock: 21 July 2026. Cutoff at 30 days = 21 June 2026.
        $now = new DateTimeImmutable('2026-07-21 09:30:00', new DateTimeZone('UTC'));
        $this->policy = new LogRetentionPolicy($now);
    }

    private function apply(string $content): LogRewriteResult
    {
        return $this->policy->apply($content, self::RETENTION_DAYS, self::SIZE_CAP);
    }

    public function testEmptyContentIsUnchanged(): void
    {
        $result = $this->apply('');

        self::assertSame(LogRewriteResult::MODE_EMPTY, $result->mode);
        self::assertSame('', $result->content);
        self::assertSame(0, $result->reclaimedBytes());
    }

    public function testRecentDatedEntriesAreKept(): void
    {
        $content = "26:07:20 09:00:00 recent entry one\n"
            . "26:07:21 10:00:00 recent entry two\n";

        $result = $this->apply($content);

        self::assertSame(LogRewriteResult::MODE_DATE, $result->mode);
        self::assertSame($content, $result->content);
        self::assertSame(0, $result->reclaimedBytes());
        self::assertFalse($result->changed());
    }

    public function testOldDatedEntriesAreDropped(): void
    {
        $old = "26:01:01 09:00:00 ancient entry\n";
        $recent = "26:07:20 09:00:00 recent entry\n";

        $result = $this->apply($old . $recent);

        self::assertSame(LogRewriteResult::MODE_DATE, $result->mode);
        self::assertSame($recent, $result->content);
        self::assertGreaterThan(0, $result->reclaimedBytes());
    }

    public function testMultiLineEntryIsKeptOrDroppedAsAUnit(): void
    {
        // A stack trace: the dated first line owns the undated continuation lines.
        $oldEntry = "26:01:01 09:00:00 Fatal error\n"
            . "  at Foo->bar()\n"
            . "  at Baz->qux()\n";
        $recentEntry = "26:07:20 09:00:00 all good\n";

        $result = $this->apply($oldEntry . $recentEntry);

        // The whole old entry — including its undated trace lines — must be gone.
        self::assertSame($recentEntry, $result->content);
        self::assertStringNotContainsString('at Foo->bar()', $result->content);
    }

    public function testRecentMultiLineEntryKeepsItsContinuationLines(): void
    {
        $recentEntry = "26:07:20 09:00:00 Fatal error\n"
            . "  at Foo->bar()\n"
            . "  at Baz->qux()\n";

        $result = $this->apply($recentEntry);

        self::assertSame($recentEntry, $result->content);
        self::assertStringContainsString('at Baz->qux()', $result->content);
    }

    public function testLeadingUndatedPreambleIsAlwaysKept(): void
    {
        // Undated lines before the first dated entry can't be dated → keep them.
        $preamble = "=== log header, no date ===\n";
        $old = "26:01:01 09:00:00 ancient\n";
        $recent = "26:07:20 09:00:00 recent\n";

        $result = $this->apply($preamble . $old . $recent);

        self::assertSame($preamble . $recent, $result->content);
        self::assertStringContainsString('log header', $result->content);
    }

    public function testEntryExactlyOnCutoffDayIsKept(): void
    {
        // Cutoff = now(2026-07-21) - 30d = 2026-06-21. An entry dated exactly then stays.
        $boundary = "26:06:21 00:00:01 boundary entry\n";
        $justBefore = "26:06:20 23:59:59 dropped entry\n";

        $result = $this->apply($justBefore . $boundary);

        self::assertSame($boundary, $result->content);
        self::assertStringNotContainsString('dropped entry', $result->content);
    }

    public function testUndatedFileIsSizeCappedNotDatePruned(): void
    {
        // No line matches the dated-entry format → size-cap fallback.
        $line = str_repeat('x', 40) . "\n"; // 41 bytes/line
        $content = str_repeat($line, 20); // 820 bytes, cap is 256

        $result = $this->apply($content);

        self::assertSame(LogRewriteResult::MODE_SIZE_CAP, $result->mode);
        self::assertLessThanOrEqual(self::SIZE_CAP, $result->newBytes);
        self::assertGreaterThan(0, $result->reclaimedBytes());
        // The tail must be kept, and must start on a clean line boundary (no partial line).
        self::assertStringEndsWith($line, $result->content);
        self::assertStringStartsWith($line, $result->content);
    }

    public function testSmallUndatedFileIsUnchanged(): void
    {
        $content = "no date here\njust two short lines\n";

        $result = $this->apply($content);

        self::assertSame(LogRewriteResult::MODE_UNCHANGED, $result->mode);
        self::assertSame($content, $result->content);
        self::assertSame(0, $result->reclaimedBytes());
    }

    public function testAllEntriesOldLeavesEmptyFile(): void
    {
        $content = "26:01:01 09:00:00 old one\n26:02:02 10:00:00 old two\n";

        $result = $this->apply($content);

        self::assertSame('', $result->content);
        self::assertSame($result->originalBytes, $result->reclaimedBytes());
    }

    public function testContentWithoutTrailingNewlineIsHandled(): void
    {
        $old = "26:01:01 09:00:00 old";
        $recent = "\n26:07:20 09:00:00 recent";

        $result = $this->apply($old . $recent);

        self::assertStringContainsString('recent', $result->content);
        self::assertStringNotContainsString('old', $result->content);
    }
}
