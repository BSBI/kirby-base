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
final readonly class UuidCachePopulateTask implements MaintenanceTask, DeferredPreviewTask
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
        return 'Fill the UUID lookup cache for every page and file, so references resolve without '
            . 'walking the site. Run after content has been copied in from elsewhere.';
    }

    public function preview(MaintenanceOptions $options): MaintenancePreview
    {
        [$pages, $files] = $this->counts();

        return new MaintenancePreview(
            $pages + $files,
            0,
            [sprintf('%d page(s) and %d file(s) would be written to the UUID cache', $pages, $files)]
        );
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
