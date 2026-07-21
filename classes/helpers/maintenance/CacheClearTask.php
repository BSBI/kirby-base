<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers\maintenance;

use Kirby\Cms\App;

/**
 * Generic maintenance task: clear Kirby's on-disk cache.
 *
 * Cache is regenerable on demand, so — unlike the other tasks — it has no age floor: the
 * whole cache directory contents are disposable. (Off-disk caches such as a Redis page
 * cache are managed by their own Panel area and are out of scope here.)
 *
 * @package BSBI\WebBase\helpers\maintenance
 */
final readonly class CacheClearTask implements MaintenanceTask
{
    /**
     * @param App $kirby the Kirby app, used only to resolve the cache root
     */
    public function __construct(private App $kirby)
    {
    }

    /**
     * @inheritDoc
     */
    public function key(): string
    {
        return 'cache';
    }

    /**
     * @inheritDoc
     */
    public function label(): string
    {
        return 'Cache';
    }

    /**
     * @inheritDoc
     */
    public function description(): string
    {
        return 'Clear the on-disk cache. Safe to run any time — cache is rebuilt on demand.';
    }

    /**
     * @inheritDoc
     */
    public function preview(MaintenanceOptions $options): MaintenancePreview
    {
        $dir = $this->cacheDir();
        if (!is_dir($dir)) {
            return MaintenancePreview::empty();
        }

        $bytes = MaintenanceFilesystem::size($dir);
        if ($bytes === 0) {
            return MaintenancePreview::empty();
        }

        return new MaintenancePreview(1, $bytes, ['Cache directory: ' . MaintenanceFilesystem::humanBytes($bytes)]);
    }

    /**
     * @inheritDoc
     */
    public function run(MaintenanceOptions $options, int $offset = 0, int $limit = 0): MaintenanceRunResult
    {
        $dir = $this->cacheDir();
        $bytes = MaintenanceFilesystem::size($dir);
        $removed = MaintenanceFilesystem::deleteContents($dir);

        return MaintenanceRunResult::completed($removed > 0 ? 1 : 0, $bytes);
    }

    /**
     * Absolute path to Kirby's cache directory.
     *
     * @return string
     */
    private function cacheDir(): string
    {
        return $this->kirby->root('cache') ?? '';
    }
}
