<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers\maintenance;

use BSBI\WebBase\helpers\maintenance\LogRetentionPolicy;
use BSBI\WebBase\helpers\maintenance\LogRetentionService;
use BSBI\WebBase\helpers\maintenance\MaintenanceOptions;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * Integration-style tests for the filesystem wrapper around {@see LogRetentionPolicy}:
 * it must prune the right files, leave the SQLite content indexes and non-log files
 * untouched, and rewrite atomically.
 */
final class LogRetentionServiceTest extends TestCase
{
    private string $dir;
    private LogRetentionService $service;

    protected function setUp(): void
    {
        $now = new DateTimeImmutable('2026-07-21 09:30:00', new DateTimeZone('UTC'));
        $this->service = new LogRetentionService(new LogRetentionPolicy($now));

        $this->dir = sys_get_temp_dir() . '/log-retention-' . uniqid('', true);
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrm($this->dir);
    }

    private function rrm(string $path): void
    {
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->rrm($path . '/' . $entry);
                }
            }
            rmdir($path);
        } elseif (is_file($path)) {
            unlink($path);
        }
    }

    private function opts(): MaintenanceOptions
    {
        return new MaintenanceOptions(30, 256);
    }

    public function testRunPrunesOldEntriesFromLogFiles(): void
    {
        $recent = "26:07:20 09:00:00 recent\n";
        file_put_contents($this->dir . '/errors.log', "26:01:01 09:00:00 old\n" . $recent);

        $result = $this->service->run($this->dir, $this->opts());

        self::assertTrue($result->done);
        self::assertGreaterThan(0, $result->reclaimedBytes);
        self::assertSame($recent, file_get_contents($this->dir . '/errors.log'));
    }

    public function testPreviewDoesNotModifyFiles(): void
    {
        $content = "26:01:01 09:00:00 old\n26:07:20 09:00:00 recent\n";
        file_put_contents($this->dir . '/errors.log', $content);

        $preview = $this->service->preview($this->dir, $this->opts());

        self::assertGreaterThan(0, $preview->bytes);
        self::assertSame(1, $preview->items);
        // File on disk is unchanged by a preview.
        self::assertSame($content, file_get_contents($this->dir . '/errors.log'));
    }

    public function testContentIndexesDirectoryIsNeverTouched(): void
    {
        mkdir($this->dir . '/content-indexes');
        // A stray *.log inside the protected dir must still be left alone.
        $indexLog = $this->dir . '/content-indexes/plants.log';
        $stale = "26:01:01 09:00:00 old but protected\n";
        file_put_contents($indexLog, $stale);
        file_put_contents($this->dir . '/content-indexes/plants.sqlite', 'BINARYDATA');

        $this->service->run($this->dir, $this->opts());

        self::assertSame($stale, file_get_contents($indexLog));
        self::assertSame('BINARYDATA', file_get_contents($this->dir . '/content-indexes/plants.sqlite'));
    }

    public function testNonLogFilesAreIgnored(): void
    {
        $csv = "26:01:01 09:00:00 old\n"; // looks prunable but is not a .log
        file_put_contents($this->dir . '/members.csv', $csv);

        $this->service->run($this->dir, $this->opts());

        self::assertSame($csv, file_get_contents($this->dir . '/members.csv'));
    }

    public function testUndatedLogIsSizeCapped(): void
    {
        $line = str_repeat('x', 40) . "\n";
        file_put_contents($this->dir . '/beacon.log', str_repeat($line, 20));

        $result = $this->service->run($this->dir, $this->opts());

        self::assertGreaterThan(0, $result->reclaimedBytes);
        self::assertLessThanOrEqual(256, strlen((string) file_get_contents($this->dir . '/beacon.log')));
    }

    public function testUnchangedFilesAreNotCounted(): void
    {
        // Entirely recent → nothing to reclaim, item count stays zero.
        file_put_contents($this->dir . '/errors.log', "26:07:20 09:00:00 recent\n");

        $preview = $this->service->preview($this->dir, $this->opts());

        self::assertSame(0, $preview->items);
        self::assertSame(0, $preview->bytes);
    }

    public function testMissingDirectoryYieldsEmptyPreview(): void
    {
        $preview = $this->service->preview($this->dir . '/does-not-exist', $this->opts());

        self::assertSame(0, $preview->items);
        self::assertSame(0, $preview->bytes);
    }
}
