<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\ErrorNotificationThrottle;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ErrorNotificationThrottle: file-based, windowed suppression of repeat
 * error notifications.
 *
 * The throttle exists to stop a site-wide fault (every request throwing) from sending
 * one alert email per request. It is deliberately file-based: the flagship flood
 * scenario is the Redis page cache being unreachable, so a cache-backed throttle would
 * fail at exactly the moment it is needed.
 */
final class ErrorNotificationThrottleTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/error-throttle-' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory) === false) {
            return;
        }

        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);
    }

    /**
     * Builds a throttle whose clock reads a caller-controlled variable, so window
     * expiry can be tested without sleeping. Uses a by-reference closure rather than
     * an arrow function, which would capture $now by value.
     *
     * @param int $now variable the injected clock reads on each call
     * @param int $window window length in seconds
     * @param string|null $directory override for the throttle directory
     */
    private function throttle(int &$now, int $window = 900, string|null $directory = null): ErrorNotificationThrottle
    {
        return new ErrorNotificationThrottle(
            $directory ?? $this->directory,
            $window,
            function () use (&$now): int {
                return $now;
            }
        );
    }

    public function testFirstNotificationForAFingerprintIsAllowed(): void
    {
        $now = 1_000_000;
        $throttle = $this->throttle($now);

        $this->assertTrue($throttle->shouldNotify('redis-down'));
    }

    public function testRepeatNotificationWithinWindowIsSuppressed(): void
    {
        $now = 1_000_000;
        $throttle = $this->throttle($now);

        $this->assertTrue($throttle->shouldNotify('redis-down'));
        $this->assertFalse($throttle->shouldNotify('redis-down'));
        $this->assertFalse($throttle->shouldNotify('redis-down'));
    }

    public function testNotificationIsAllowedAgainOnceWindowHasPassed(): void
    {
        $now = 1_000_000;
        $throttle = $this->throttle($now);

        $this->assertTrue($throttle->shouldNotify('redis-down'));

        $now += 899;
        $this->assertFalse($throttle->shouldNotify('redis-down'), 'still inside the window');

        $now += 2;
        $this->assertTrue($throttle->shouldNotify('redis-down'), 'window has elapsed');
    }

    public function testDistinctFingerprintsAreThrottledIndependently(): void
    {
        $now = 1_000_000;
        $throttle = $this->throttle($now);

        $this->assertTrue($throttle->shouldNotify('redis-down'));
        $this->assertTrue($throttle->shouldNotify('out-of-memory'));
        $this->assertFalse($throttle->shouldNotify('redis-down'));
        $this->assertFalse($throttle->shouldNotify('out-of-memory'));
    }

    /**
     * A fingerprint is derived from an exception message, so it is untrusted input and
     * must never reach the filesystem verbatim.
     */
    public function testFingerprintCannotEscapeTheThrottleDirectory(): void
    {
        $now = 1_000_000;
        $throttle = $this->throttle($now);

        $this->assertTrue($throttle->shouldNotify('../../../../etc/passwd'));
        $this->assertFalse($throttle->shouldNotify('../../../../etc/passwd'));

        $written = glob($this->directory . '/*') ?: [];
        $this->assertCount(1, $written);
        $this->assertStringStartsWith($this->directory . '/', $written[0]);
    }

    public function testCreatesThrottleDirectoryWhenMissing(): void
    {
        $now = 1_000_000;
        $this->assertDirectoryDoesNotExist($this->directory);

        $throttle = $this->throttle($now);
        $throttle->shouldNotify('redis-down');

        $this->assertDirectoryExists($this->directory);
    }

    /**
     * If the throttle cannot record state it must fail open. Losing every error alert
     * is worse than sending too many, and this keeps behaviour no worse than before
     * the throttle existed.
     */
    public function testFailsOpenWhenStateCannotBeRecorded(): void
    {
        $now = 1_000_000;
        $blocker = sys_get_temp_dir() . '/error-throttle-blocker-' . uniqid();
        file_put_contents($blocker, 'not a directory');

        // Pointing the throttle at a regular file makes mkdir() and file writes fail.
        $throttle = $this->throttle($now, 900, $blocker);

        $this->assertTrue($throttle->shouldNotify('redis-down'));
        $this->assertTrue($throttle->shouldNotify('redis-down'), 'still notifies rather than going silent');

        @unlink($blocker);
    }

    public function testZeroWindowDisablesThrottling(): void
    {
        $now = 1_000_000;
        $throttle = $this->throttle($now, 0);

        $this->assertTrue($throttle->shouldNotify('redis-down'));
        $this->assertTrue($throttle->shouldNotify('redis-down'));
    }
}
