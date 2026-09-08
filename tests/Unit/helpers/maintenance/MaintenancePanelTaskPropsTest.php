<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers\maintenance;

use BSBI\WebBase\helpers\maintenance\DanglingReferenceAuditTask;
use BSBI\WebBase\helpers\maintenance\MaintenanceOptions;
use BSBI\WebBase\helpers\maintenance\MaintenancePanel;
use BSBI\WebBase\helpers\maintenance\MaintenancePreview;
use BSBI\WebBase\helpers\maintenance\MaintenanceRunResult;
use BSBI\WebBase\helpers\maintenance\MaintenanceTask;
use BSBI\WebBase\helpers\maintenance\NonDestructiveTask;
use BSBI\WebBase\helpers\maintenance\UuidCachePopulateTask;
use BSBI\WebBase\Testing\KirbyTestEnvironment;
use Kirby\Cms\App;
use PHPUnit\Framework\TestCase;

/**
 * The task card props the Panel renders (bsbi-web#734): cleanup tasks keep the
 * "Would free …" wording and the bin icon; a task that frees nothing supplies
 * its own summary, empty text and icon, and is flagged non-destructive.
 */
final class MaintenancePanelTaskPropsTest extends TestCase
{
    private static App $kirby;

    public static function setUpBeforeClass(): void
    {
        self::$kirby = KirbyTestEnvironment::bootWithContent(
            dirname(__DIR__, 3) . '/fixtures/uuid-content',
            'kirby-base-task-props'
        );
    }

    private function cleanupStub(): MaintenanceTask
    {
        return new class implements MaintenanceTask {
            public function key(): string { return 'stub'; }
            public function label(): string { return 'Stub files'; }
            public function description(): string { return 'Removes stubs.'; }
            public function preview(MaintenanceOptions $options): MaintenancePreview { return new MaintenancePreview(3, 2048, ['a', 'b']); }
            public function run(MaintenanceOptions $options, int $offset = 0, int $limit = 0): MaintenanceRunResult { return MaintenanceRunResult::completed(3, 2048); }
        };
    }

    public function testCleanupTaskKeepsTheDefaultWording(): void
    {
        $props = MaintenancePanel::taskProps($this->cleanupStub(), new MaintenanceOptions());

        self::assertTrue($props['destructive']);
        self::assertSame('trash', $props['icon']);
        self::assertNull($props['summary']);
        self::assertNull($props['emptySummary']);
        self::assertSame(3, $props['items']);
        self::assertSame('2 KB', $props['humanBytes']);
        self::assertFalse($props['deferred']);
        self::assertFalse($props['error']);
    }

    public function testNonDestructiveTasksDescribeThemselves(): void
    {
        $audit = new DanglingReferenceAuditTask(self::$kirby);
        self::assertInstanceOf(NonDestructiveTask::class, $audit);
        $props = MaintenancePanel::taskProps($audit, new MaintenanceOptions(), deferred: false);

        self::assertFalse($props['destructive']);
        self::assertSame('search', $props['icon']);
        self::assertSame('Would list 4 dangling reference(s) on 2 page(s)', $props['summary']);
        self::assertSame('No dangling references', $props['emptySummary']);

        $populate = new UuidCachePopulateTask(self::$kirby);
        self::assertInstanceOf(NonDestructiveTask::class, $populate);
        $props = MaintenancePanel::taskProps($populate, new MaintenanceOptions(), deferred: false);

        self::assertFalse($props['destructive']);
        self::assertSame('refresh', $props['icon']);
        self::assertSame('Would write 3 page and file UUID(s) to the lookup cache', $props['summary']);
        self::assertStringContainsString('localhost and staging', $props['description']);
        self::assertStringContainsString('Not needed on live', $props['description']);
    }

    public function testDeferredPlaceholderCarriesTheWordingKeys(): void
    {
        $props = MaintenancePanel::taskProps(new DanglingReferenceAuditTask(self::$kirby), new MaintenanceOptions(), deferred: true);

        self::assertTrue($props['deferred']);
        self::assertSame(0, $props['items']);
        self::assertFalse($props['destructive']);
        self::assertSame('search', $props['icon']);
        self::assertSame('No dangling references', $props['emptySummary']);
    }
}
