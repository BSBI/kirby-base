<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

use Closure;

/**
 * Windowed suppression of repeat error notifications.
 *
 * Without throttling, a site-wide fault sends one alert email per request: a fault that
 * makes every page throw produces an email for every visitor hit. That is enough to get
 * the sending domain rate-limited, which takes out the site's transactional mail
 * (membership, payments, password resets) as well as the alert channel itself — the
 * alerting amplifies the outage it is meant to report.
 *
 * State is held in marker files rather than the Kirby cache on purpose. The most likely
 * flood scenario is the Redis page cache being unreachable, so a cache-backed throttle
 * would fail at precisely the moment it is needed.
 *
 * Throttling is per fingerprint, so distinct faults each get their own alert rather than
 * the first fault masking the rest.
 */
final class ErrorNotificationThrottle
{
    /**
     * Default window between notifications for the same fingerprint (15 minutes),
     * i.e. at most four alerts per hour for a persistent fault.
     */
    public const int DEFAULT_WINDOW_SECONDS = 900;

    /** @var Closure(): int */
    private Closure $clock;

    /**
     * @param string $directory directory used to hold marker files
     * @param int $windowSeconds seconds to suppress repeat notifications for a fingerprint;
     *                           0 disables throttling entirely
     * @param Closure(): int|null $clock returns the current unix timestamp; defaults to time()
     */
    public function __construct(
        private readonly string $directory,
        private readonly int $windowSeconds = self::DEFAULT_WINDOW_SECONDS,
        Closure|null $clock = null
    ) {
        $this->clock = $clock ?? static fn(): int => time();
    }

    /**
     * Decides whether an alert for this fingerprint should be sent now, and records the
     * decision so repeats inside the window are suppressed.
     *
     * Fails open: if the marker cannot be read or written, notification is allowed. Going
     * silent would be worse than sending too much, and it keeps behaviour no worse than
     * having no throttle at all.
     *
     * @param string $fingerprint stable identifier for the fault (e.g. message + file + line)
     * @return bool true if the caller should send a notification
     */
    public function shouldNotify(string $fingerprint): bool
    {
        if ($this->windowSeconds <= 0) {
            return true;
        }

        $marker = $this->markerPath($fingerprint);

        if ($marker === null) {
            return true;
        }

        $now = ($this->clock)();
        $lastNotifiedAt = $this->lastNotifiedAt($marker);

        if ($lastNotifiedAt !== null && ($now - $lastNotifiedAt) < $this->windowSeconds) {
            return false;
        }

        // A concurrent request can slip through between the read and the write. That is
        // acceptable: the goal is to turn thousands of alerts into a handful, not to
        // guarantee exactly one.
        $this->record($marker, $now);

        return true;
    }

    /**
     * Builds the marker file path for a fingerprint, creating the directory if needed.
     *
     * The fingerprint is hashed rather than used verbatim: it derives from exception
     * messages, so it is untrusted input that must never reach the filesystem as a path.
     *
     * @param string $fingerprint stable identifier for the fault
     * @return string|null the marker path, or null if the directory is unusable
     */
    private function markerPath(string $fingerprint): string|null
    {
        if (is_dir($this->directory) === false && @mkdir($this->directory, 0o755, true) === false) {
            // Lost a race with a concurrent request is fine; anything else is not usable.
            if (is_dir($this->directory) === false) {
                return null;
            }
        }

        return $this->directory . '/' . hash('sha256', $fingerprint) . '.marker';
    }

    /**
     * Reads the timestamp of the last notification for a marker.
     *
     * @param string $marker marker file path
     * @return int|null the timestamp, or null if never notified or unreadable
     */
    private function lastNotifiedAt(string $marker): int|null
    {
        if (is_file($marker) === false) {
            return null;
        }

        $contents = @file_get_contents($marker);

        if ($contents === false || is_numeric(trim($contents)) === false) {
            return null;
        }

        return (int) trim($contents);
    }

    /**
     * Records that a notification was sent at the given time.
     *
     * The timestamp is written to the file body rather than relying on mtime, which some
     * hosts and filesystems report at coarse resolution.
     *
     * @param string $marker marker file path
     * @param int $now unix timestamp to record
     */
    private function record(string $marker, int $now): void
    {
        @file_put_contents($marker, (string) $now, LOCK_EX);
    }
}
