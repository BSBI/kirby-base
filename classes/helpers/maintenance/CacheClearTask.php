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
    /** Kirby's UUID lookup cache lives here under the cache root; it is an index, not derived output, and is kept (bsbi-web#734). */
    private const string UUID_CACHE_DIR = 'uuid';

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
        return 'Clear the on-disk cache. Safe to run any time — cache is rebuilt on demand. '
            . 'The UUID lookup cache is kept: clearing it would make every page and file reference '
            . 'walk the site once on its next lookup.';
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

        $bytes = $this->clearableBytes($dir);
        if ($bytes === 0) {
            return MaintenancePreview::empty();
        }

        return new MaintenancePreview(1, $bytes, [
            'Cache directory: ' . MaintenanceFilesystem::humanBytes($bytes),
            'UUID lookup cache kept: ' . MaintenanceFilesystem::humanBytes($this->uuidBytes($dir)),
        ]);
    }

    /**
     * @inheritDoc
     */
    public function run(MaintenanceOptions $options, int $offset = 0, int $limit = 0): MaintenanceRunResult
    {
        $dir = $this->cacheDir();
        $bytes = $this->clearableBytes($dir);
        $removed = MaintenanceFilesystem::deleteContents($dir, [self::UUID_CACHE_DIR]);

        return MaintenanceRunResult::completed($removed > 0 ? 1 : 0, $bytes);
    }

    /**
     * Bytes the run would remove: the cache root minus the kept UUID lookup cache.
     */
    private function clearableBytes(string $dir): int
    {
        return max(0, MaintenanceFilesystem::size($dir) - $this->uuidBytes($dir));
    }

    /**
     * Size of the UUID lookup cache under the cache root, 0 when absent.
     */
    private function uuidBytes(string $dir): int
    {
        $uuidDir = $dir . '/' . self::UUID_CACHE_DIR;

        return is_dir($uuidDir) ? MaintenanceFilesystem::size($uuidDir) : 0;
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
