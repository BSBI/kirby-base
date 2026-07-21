<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers\maintenance;

use BSBI\WebBase\helpers\maintenance\MaintenanceOptions;
use BSBI\WebBase\helpers\maintenance\MaintenancePreview;
use BSBI\WebBase\helpers\maintenance\MaintenanceRegistry;
use BSBI\WebBase\helpers\maintenance\MaintenanceRunResult;
use BSBI\WebBase\helpers\maintenance\MaintenanceTask;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the static task registry that lets the Panel list generic (kirby-base) and
 * site-specific (bsbi-web) tasks uniformly.
 */
final class MaintenanceRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        MaintenanceRegistry::clear();
    }

    protected function tearDown(): void
    {
        MaintenanceRegistry::clear();
    }

    private function stubTask(string $key): MaintenanceTask
    {
        return new class ($key) implements MaintenanceTask {
            public function __construct(private readonly string $key)
            {
            }

            public function key(): string
            {
                return $this->key;
            }

            public function label(): string
            {
                return 'Label ' . $this->key;
            }

            public function description(): string
            {
                return 'Description ' . $this->key;
            }

            public function preview(MaintenanceOptions $options): MaintenancePreview
            {
                return MaintenancePreview::empty();
            }

            public function run(MaintenanceOptions $options, int $offset = 0, int $limit = 0): MaintenanceRunResult
            {
                return MaintenanceRunResult::completed(0, 0);
            }
        };
    }

    public function testRegisterAndGet(): void
    {
        $task = $this->stubTask('logs');
        MaintenanceRegistry::register($task);

        self::assertSame($task, MaintenanceRegistry::get('logs'));
    }

    public function testGetUnknownKeyReturnsNull(): void
    {
        self::assertNull(MaintenanceRegistry::get('nope'));
    }

    public function testAllPreservesRegistrationOrder(): void
    {
        MaintenanceRegistry::register($this->stubTask('logs'));
        MaintenanceRegistry::register($this->stubTask('cache'));
        MaintenanceRegistry::register($this->stubTask('import'));

        self::assertSame(['logs', 'cache', 'import'], array_keys(MaintenanceRegistry::all()));
    }

    public function testLaterRegistrationReplacesSameKey(): void
    {
        $first = $this->stubTask('logs');
        $second = $this->stubTask('logs');

        MaintenanceRegistry::register($first);
        MaintenanceRegistry::register($second);

        self::assertSame($second, MaintenanceRegistry::get('logs'));
        self::assertCount(1, MaintenanceRegistry::all());
    }

    public function testClearEmptiesTheRegistry(): void
    {
        MaintenanceRegistry::register($this->stubTask('logs'));
        MaintenanceRegistry::clear();

        self::assertSame([], MaintenanceRegistry::all());
    }
}
