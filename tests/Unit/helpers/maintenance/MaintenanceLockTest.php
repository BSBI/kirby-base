<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers\maintenance;

use BSBI\WebBase\helpers\maintenance\MaintenanceLock;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the per-task write lock guarding against overlapping maintenance runs.
 */
final class MaintenanceLockTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/maintenance-lock-' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            foreach (scandir($this->dir) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    @unlink($this->dir . '/' . $entry);
                }
            }
            rmdir($this->dir);
        }
    }

    public function testAcquireSucceedsThenBlocksSecondAcquire(): void
    {
        $lock = new MaintenanceLock($this->dir);

        self::assertTrue($lock->acquire('logs'));
        self::assertFalse($lock->acquire('logs'));
        self::assertTrue($lock->isLocked('logs'));
    }

    public function testDifferentKeysDoNotBlockEachOther(): void
    {
        $lock = new MaintenanceLock($this->dir);

        self::assertTrue($lock->acquire('logs'));
        self::assertTrue($lock->acquire('import'));
    }

    public function testReleaseAllowsReacquire(): void
    {
        $lock = new MaintenanceLock($this->dir);

        self::assertTrue($lock->acquire('logs'));
        $lock->release('logs');
        self::assertFalse($lock->isLocked('logs'));
        self::assertTrue($lock->acquire('logs'));
    }

    public function testStaleLockIsReclaimed(): void
    {
        // Timeout of 0 seconds → any existing lock is immediately stale.
        $lock = new MaintenanceLock($this->dir, 0);

        self::assertTrue($lock->acquire('logs'));
        // Back-date the lock file so it is provably older than the (0s) timeout.
        touch($this->dir . '/logs.lock', time() - 10);
        self::assertTrue($lock->acquire('logs'));
    }

    public function testKeyIsSanitisedToASafeFilename(): void
    {
        $lock = new MaintenanceLock($this->dir);

        self::assertTrue($lock->acquire('../weird/key'));
        // The lock file lands inside the lock dir, not via path traversal.
        $files = array_values(array_filter(
            scandir($this->dir) ?: [],
            static fn (string $f): bool => str_ends_with($f, '.lock'),
        ));
        self::assertCount(1, $files);
    }
}
