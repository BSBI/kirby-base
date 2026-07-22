<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers\maintenance;

use BSBI\WebBase\helpers\maintenance\MaintenanceFilesystem;
use BSBI\WebBase\helpers\maintenance\MaintenancePanel;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the pure disk-report assembly that backs the maintenance dashboard gauge.
 *
 * The gauge derives "used" from {@see disk_free_space()}, which excludes the filesystem's
 * root-reserved blocks — so it reads a little higher than a host dashboard that counts those
 * blocks as free. {@see MaintenancePanel::diskReport()} carries a note explaining this.
 */
final class MaintenancePanelDiskReportTest extends TestCase
{
    public function testComputesUsedPercentFromFreeAndTotal(): void
    {
        $report = MaintenancePanel::diskReport(100, 200);

        self::assertSame(100, $report['freeBytes']);
        self::assertSame(200, $report['totalBytes']);
        self::assertSame(50, $report['usedPercent']);
    }

    public function testRoundsUsedPercentToNearestWholeNumber(): void
    {
        // 116.49 / 314.81 free ⇒ ~63% used, matching the live dashboard.
        $report = MaintenancePanel::diskReport(116_490_000_000, 314_810_000_000);

        self::assertSame(63, $report['usedPercent']);
    }

    public function testHumanFiguresMatchTheFilesystemFormatter(): void
    {
        $report = MaintenancePanel::diskReport(1_500_000_000, 3_000_000_000);

        self::assertSame(MaintenanceFilesystem::humanBytes(1_500_000_000), $report['freeHuman']);
        self::assertSame(MaintenanceFilesystem::humanBytes(3_000_000_000), $report['totalHuman']);
    }

    public function testZeroTotalYieldsZeroPercentAndDoesNotDivideByZero(): void
    {
        $report = MaintenancePanel::diskReport(0, 0);

        self::assertSame(0, $report['usedPercent']);
    }

    public function testCarriesTheReservedBlocksNote(): void
    {
        $report = MaintenancePanel::diskReport(100, 200);

        self::assertSame(MaintenancePanel::FREE_SPACE_NOTE, $report['note']);
        self::assertStringContainsStringIgnoringCase('reserve', $report['note']);
        self::assertStringContainsStringIgnoringCase('hosting', $report['note']);
    }
}
