<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers\maintenance;

use DateTimeImmutable;
use Kirby\Cms\App;

/**
 * Generic maintenance task: reclaim disk by deleting old thumbnail/derivative directories under
 * `media/pages/`.
 *
 * A thin Kirby adapter — it resolves the media root from the app and delegates all logic to the
 * unit-tested {@see MediaGarbageCollector}. **Chunked**: each `run()` processes a bounded slice of
 * page directories and reports the next offset for the Panel loop.
 *
 * Media is a disposable cache: originals live in `content/`, so nothing here is irreplaceable.
 * Deleting an old hash dir just forces Kirby to lazily regenerate whatever is still requested,
 * which also sheds accumulated cruft (obsolete responsive widths, dead formats). The retention
 * window spares recently-generated media to bound the regeneration load.
 *
 * @package BSBI\WebBase\helpers\maintenance
 */
final readonly class MediaCleanupTask implements MaintenanceTask
{
    /** Page directories processed per chunk when the caller supplies no limit. */
    public const int DEFAULT_CHUNK_SIZE = 250;

    private MediaGarbageCollector $collector;

    /**
     * @param App $kirby the Kirby app, used to resolve the media root
     */
    public function __construct(private App $kirby)
    {
        $this->collector = new MediaGarbageCollector(new DateTimeImmutable('now'));
    }

    /**
     * @inheritDoc
     */
    public function key(): string
    {
        return 'media';
    }

    /**
     * @inheritDoc
     */
    public function label(): string
    {
        return 'Media cache';
    }

    /**
     * @inheritDoc
     */
    public function description(): string
    {
        return 'Delete cached image thumbnails older than the retention window to reclaim disk. '
            . 'Originals are kept; Kirby regenerates thumbnails on demand. Downloads are unaffected.';
    }

    /**
     * @inheritDoc
     */
    public function preview(MaintenanceOptions $options): MaintenancePreview
    {
        return $this->collector->preview($this->mediaPagesDir(), $options);
    }

    /**
     * @inheritDoc
     */
    public function run(MaintenanceOptions $options, int $offset = 0, int $limit = 0): MaintenanceRunResult
    {
        $chunk = $limit > 0 ? $limit : self::DEFAULT_CHUNK_SIZE;

        return $this->collector->runChunk($this->mediaPagesDir(), $options, max(0, $offset), $chunk);
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
