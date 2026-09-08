<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers\maintenance;

use BSBI\WebBase\helpers\UuidResolver;
use Kirby\Cms\App;
use Kirby\Uuid\Uuids;

/**
 * Fills Kirby's uuid cache for every page and file in one walk (bsbi-web#732).
 *
 * Kirby caches a UUID only when the model is created through Kirby or looked
 * up once; content that arrives by filesystem copy (a localhost refresh, a
 * staging sync, a restore) is uncached until each UUID's first lookup walks the
 * whole site. Running this after such a copy makes every existing UUID a cache
 * hit, and clears the resolver's remembered misses.
 *
 * The preview walks the site to count pages and files, so it is deferred.
 */
final readonly class UuidCachePopulateTask implements MaintenanceTask, DeferredPreviewTask, NonDestructiveTask
{
    public function __construct(private App $kirby)
    {
    }

    public function key(): string
    {
        return 'uuid-cache';
    }

    public function label(): string
    {
        return 'UUID cache';
    }

    public function description(): string
    {
        return 'For localhost and staging after content has been copied in from live: fills the UUID '
            . 'lookup cache for every page and file so no first lookup walks the site. Not needed on live — '
            . 'Kirby caches pages and files as editors create them, and the Cache task keeps this cache.';
    }

    public function preview(MaintenanceOptions $options): MaintenancePreview
    {
        [$pages, $files] = $this->counts();

        return new MaintenancePreview(
            $pages + $files,
            0,
            [sprintf('%d page(s) and %d file(s)', $pages, $files)],
            sprintf('Would write %d page and file UUID(s) to the lookup cache', $pages + $files),
            $this->emptySummary()
        );
    }

    public function icon(): string
    {
        return 'refresh';
    }

    public function emptySummary(): string
    {
        return 'No pages or files to cache';
    }

    public function run(MaintenanceOptions $options, int $offset = 0, int $limit = 0): MaintenanceRunResult
    {
        Uuids::populate('page');
        Uuids::populate('file');
        (new UuidResolver($this->kirby))->forgetMisses();

        [$pages, $files] = $this->counts();

        return new MaintenanceRunResult(true, $pages + $files, 0);
    }

    /**
     * @return array{int, int} pages, files
     */
    private function counts(): array
    {
        $index = $this->kirby->site()->index(true);

        return [$index->count(), $index->files()->count()];
    }
}
