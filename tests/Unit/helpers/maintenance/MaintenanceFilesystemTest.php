<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers\maintenance;

use BSBI\WebBase\helpers\maintenance\MaintenanceFilesystem;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the shared filesystem primitives used by the maintenance tasks.
 */
final class MaintenanceFilesystemTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/maint-fs-' . uniqid('', true);
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        MaintenanceFilesystem::delete($this->dir);
    }

    private function makeFile(string $rel, int $bytes, ?int $mtime = null): void
    {
        $full = $this->dir . '/' . $rel;
        $parent = dirname($full);
        if (!is_dir($parent)) {
            mkdir($parent, 0777, true);
        }
        file_put_contents($full, str_repeat('x', $bytes));
        if ($mtime !== null) {
            touch($full, $mtime);
        }
    }

    public function testSizeSumsSubtree(): void
    {
        $this->makeFile('a.txt', 100);
        $this->makeFile('sub/b.txt', 250);

        self::assertSame(350, MaintenanceFilesystem::size($this->dir));
    }

    public function testSizeOfAbsentPathIsZero(): void
    {
        self::assertSame(0, MaintenanceFilesystem::size($this->dir . '/nope'));
    }

    public function testNewestFileMtimeIgnoresDirectoryMtime(): void
    {
        $old = time() - 100000;
        $newer = time() - 500;
        $this->makeFile('sub/old.txt', 10, $old);
        $this->makeFile('sub/new.txt', 10, $newer);

        self::assertSame($newer, MaintenanceFilesystem::newestFileMtime($this->dir . '/sub'));
    }

    public function testDeleteRemovesSubtree(): void
    {
        $this->makeFile('sub/b.txt', 10);

        self::assertTrue(MaintenanceFilesystem::delete($this->dir . '/sub'));
        self::assertDirectoryDoesNotExist($this->dir . '/sub');
    }

    public function testDeleteContentsKeepsTheDirectoryItself(): void
    {
        $this->makeFile('a.txt', 10);
        $this->makeFile('sub/b.txt', 10);

        $removed = MaintenanceFilesystem::deleteContents($this->dir);

        self::assertSame(2, $removed);
        self::assertDirectoryExists($this->dir);
        self::assertSame(0, MaintenanceFilesystem::size($this->dir));
    }

    public function testHumanBytesFormats(): void
    {
        self::assertSame('512 B', MaintenanceFilesystem::humanBytes(512));
        self::assertSame('1 KB', MaintenanceFilesystem::humanBytes(1024));
        self::assertSame('1 MB', MaintenanceFilesystem::humanBytes(1048576));
        self::assertSame('1 GB', MaintenanceFilesystem::humanBytes(1073741824));
    }
}
