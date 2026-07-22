<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers\maintenance;

use DateTimeImmutable;
use Kirby\Cms\App;

/**
 * Generic **destructive** maintenance task: blanket-wipe every derivative under `media/pages/`,
 * ignoring the retention window entirely.
 *
 * Unlike the age-gated {@see MediaCleanupTask} (safe to run on live), this deletes *all* cached
 * media — recent thumbnails included — so Kirby regenerates the whole cache lazily afterwards. It
 * exists to refresh a staging mirror to a minimal disk footprint, and must therefore never run on
 * production: a {@see MaintenanceGuard} is injected and re-asserted at the top of both
 * {@see preview()} and {@see run()}, so even if the task were mistakenly registered somewhere it
 * must not be, it deletes nothing.
 *
 * Media is a disposable cache (originals live in `content/`; file-archive downloads stream from the
 * original, never from `media/`), which is what makes a blanket wipe recoverable. It reuses the
 * unit-tested {@see MediaGarbageCollector} walk in `wipeAll` mode, so it is chunked and
 * resumable-under-deletion like the live cleanup.
 *
 * @package BSBI\WebBase\helpers\maintenance
 */
final readonly class MediaWipeTask implements MaintenanceTask, DeferredPreviewTask
{
    /** Page directories processed per chunk when the caller supplies no limit. */
    public const int DEFAULT_CHUNK_SIZE = 250;

    private MediaGarbageCollector $collector;

    /**
     * @param App $kirby the Kirby app, used to resolve the media root
     * @param MaintenanceGuard $guard re-asserted before any preview/deletion so the wipe can never
     *        run in a forbidden (e.g. live) environment
     */
    public function __construct(
        private App $kirby,
        private MaintenanceGuard $guard,
    ) {
        $this->collector = new MediaGarbageCollector(new DateTimeImmutable('now'));
    }

    /**
     * @inheritDoc
     */
    public function key(): string
    {
        return 'media-wipe';
    }

    /**
     * @inheritDoc
     */
    public function label(): string
    {
        return 'Media (wipe all)';
    }

    /**
     * @inheritDoc
     */
    public function description(): string
    {
        return 'DEV & STAGING ONLY: delete every cached image thumbnail — recent ones included — to '
            . 'reclaim maximum disk. Originals are kept; Kirby regenerates thumbnails on demand.';
    }

    /**
     * @inheritDoc
     * @throws MaintenanceGuardException if the wipe is not permitted in this environment
     */
    public function preview(MaintenanceOptions $options): MaintenancePreview
    {
        $this->guard->assertPermitted();

        return $this->collector->preview($this->mediaPagesDir(), $options, true);
    }

    /**
     * @inheritDoc
     * @throws MaintenanceGuardException if the wipe is not permitted in this environment
     */
    public function run(MaintenanceOptions $options, int $offset = 0, int $limit = 0): MaintenanceRunResult
    {
        $this->guard->assertPermitted();

        $chunk = $limit > 0 ? $limit : self::DEFAULT_CHUNK_SIZE;

        return $this->collector->runChunk($this->mediaPagesDir(), $options, max(0, $offset), $chunk, true);
    }

    /**
     * Absolute path to Kirby's `media/pages` directory.
     *
     * @return string
     */
    private function mediaPagesDir(): string
    {
        return ($this->kirby->root('media') ?? '') . '/pages';
    }
}
