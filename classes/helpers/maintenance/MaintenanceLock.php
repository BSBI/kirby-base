<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers\maintenance;

/**
 * A per-task advisory lock backed by a lock file, so two admins (or a dropped-then-
 * re-clicked tab) cannot run the same destructive task concurrently.
 *
 * Acquisition is atomic via `fopen(..., 'x')`. A stale lock older than the timeout is
 * reclaimed automatically, so a process that died mid-run does not wedge the task forever.
 *
 * @package BSBI\WebBase\helpers\maintenance
 */
final readonly class MaintenanceLock
{
    /** Default age after which a held lock is considered abandoned. */
    public const int DEFAULT_STALE_SECONDS = 3600;

    /**
     * @param string $lockDir directory that holds the lock files
     * @param int $staleSeconds age after which an existing lock is treated as stale
     */
    public function __construct(
        private string $lockDir,
        private int $staleSeconds = self::DEFAULT_STALE_SECONDS,
    ) {
    }

    /**
     * Attempt to acquire the lock for a task key.
     *
     * @param string $key the task key to lock
     * @return bool true if the lock was acquired, false if another run holds it
     */
    public function acquire(string $key): bool
    {
        if (!is_dir($this->lockDir) && !@mkdir($this->lockDir, 0755, true) && !is_dir($this->lockDir)) {
            return false;
        }

        $path = $this->path($key);

        // Reclaim an abandoned lock before trying to create a fresh one.
        if (is_file($path) && (time() - (int) filemtime($path)) > $this->staleSeconds) {
            @unlink($path);
        }

        $handle = @fopen($path, 'x');
        if ($handle === false) {
            return false; // already held
        }

        fwrite($handle, (string) time());
        fclose($handle);

        return true;
    }

    /**
     * Release the lock for a task key.
     *
     * @param string $key the task key to unlock
     * @return void
     */
    public function release(string $key): void
    {
        $path = $this->path($key);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Whether the lock for a task key is currently held (and not stale).
     *
     * @param string $key the task key
     * @return bool
     */
    public function isLocked(string $key): bool
    {
        $path = $this->path($key);
        if (!is_file($path)) {
            return false;
        }

        return (time() - (int) filemtime($path)) <= $this->staleSeconds;
    }

    /**
     * Absolute path of the lock file for a key, with the key sanitised to a safe filename.
     *
     * @param string $key the task key
     * @return string
     */
    private function path(string $key): string
    {
        $safe = preg_replace('/[^a-z0-9_-]/i', '_', $key) ?? 'task';
        return rtrim($this->lockDir, '/') . '/' . $safe . '.lock';
    }
}
