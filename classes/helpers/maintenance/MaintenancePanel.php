<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers\maintenance;

use BSBI\WebBase\helpers\KirbyInternalHelper;
use Kirby\Cms\App;
use Kirby\Http\Response;
use Throwable;

/**
 * Backend for the maintenance Panel area: assembles the dashboard (disk usage + a dry-run
 * preview per registered task) and executes a single task run under a per-task write lock.
 *
 * All destructive endpoints are admin-only. Keeping this logic here (rather than inline in
 * the plugin's `index.php`) mirrors the redis-cache dashboard and keeps the route wiring
 * trivial.
 *
 * @package BSBI\WebBase\helpers\maintenance
 */
final class MaintenancePanel
{
    /**
     * Explains why the gauge's free figure can read lower than the hosting dashboard: it is
     * derived from {@see disk_free_space()}, which excludes the filesystem's root-reserved
     * blocks (space the site cannot use), whereas most host panels count those as free.
     */
    public const string FREE_SPACE_NOTE = 'Free space is what the site can actually use. '
        . 'The filesystem keeps some blocks reserved for the operating system, so this may read '
        . 'lower than your hosting dashboard. Those reserved blocks are not reclaimable here.';

    /**
     * Assemble the Vue view props: whether the viewer may act, the retention window, disk
     * usage, and a dry-run preview for every registered task.
     *
     * Disk usage and task previews are only computed for admins — a non-admin gets an
     * unpopulated payload (and the view shows an "administrators only" notice), so nothing
     * sensitive is disclosed in the props JSON.
     *
     * @param App $kirby the Kirby app
     * @return array{authorized: bool, retentionDays: int, disk: array{freeBytes: int, totalBytes: int, usedPercent: int, freeHuman: string, totalHuman: string, note: string}|null, tasks: array<int, array{key: string, label: string, description: string, items: int, bytes: int, humanBytes: string, sample: array<int, string>, error: bool, deferred: bool}>}
     */
    public static function dashboardProps(App $kirby): array
    {
        $authorized = self::isAdmin();
        $options = new MaintenanceOptions();

        if (!$authorized) {
            return [
                'authorized'    => false,
                'retentionDays' => $options->retentionDays,
                'disk'          => null,
                'tasks'         => [],
            ];
        }

        $tasks = [];
        foreach (MaintenanceRegistry::all() as $task) {
            // Expensive previews (e.g. the media walk) are deferred: return a placeholder now and
            // let the client fetch the real preview from the time-limit-lifted preview endpoint,
            // so a slow scan never blocks or times out this 30-second dashboard request.
            $tasks[] = $task instanceof DeferredPreviewTask
                ? self::deferredPlaceholder($task)
                : self::previewTask($task, $options);
        }

        return [
            'authorized'    => true,
            'retentionDays' => $options->retentionDays,
            'disk'          => self::diskUsage($kirby),
            'tasks'         => $tasks,
        ];
    }

    /**
     * Run a single task chunk. Admin-gated, locked against concurrent runs, and executed
     * with lifted time/memory limits so a blocking task can reach the web server timeout.
     *
     * @param App $kirby the Kirby app
     * @return Response JSON result, or an error status (403/404/409/500)
     */
    public static function run(App $kirby): Response
    {
        if (!self::isAdmin()) {
            return self::error('You must be an administrator to run maintenance.', 403);
        }

        $request = $kirby->request();
        $key = (string) $request->get('key', '');
        $task = MaintenanceRegistry::get($key);

        if ($task === null) {
            return self::error("Unknown maintenance task '{$key}'.", 404);
        }

        $retentionDays = $request->get('retentionDays');
        $options = MaintenanceOptions::fromRetentionDays(
            is_numeric($retentionDays) ? (int) $retentionDays : null,
        );
        $offset = (int) $request->get('offset', 0);
        $limit  = (int) $request->get('limit', 0);

        $lock = new MaintenanceLock(self::lockDir($kirby));
        if (!$lock->acquire($key)) {
            return self::error("The '{$key}' task is already running. Please wait for it to finish.", 409);
        }

        // Lift limits so a blocking task can reach the web server's ~300s timeout.
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        try {
            $result = $task->run($options, $offset, $limit);
        } catch (Throwable $e) {
            return self::error('Maintenance task failed: ' . $e->getMessage(), 500);
        } finally {
            $lock->release($key);
        }

        return Response::json([
            'done'           => $result->done,
            'processed'      => $result->processed,
            'reclaimedBytes' => $result->reclaimedBytes,
            'humanReclaimed' => MaintenanceFilesystem::humanBytes($result->reclaimedBytes),
            'nextOffset'     => $result->nextOffset,
        ], 200);
    }

    /**
     * Compute a single deferred task's preview on demand, with lifted time/memory limits so a
     * full-tree scan (e.g. media) can reach the web server timeout instead of blocking the
     * dashboard. Admin-gated.
     *
     * @param App $kirby the Kirby app
     * @return Response JSON task preview, or an error status (403/404/500)
     */
    public static function previewOne(App $kirby): Response
    {
        if (!self::isAdmin()) {
            return self::error('You must be an administrator to run maintenance.', 403);
        }

        $request = $kirby->request();
        $key = (string) $request->get('key', '');
        $task = MaintenanceRegistry::get($key);

        if ($task === null) {
            return self::error("Unknown maintenance task '{$key}'.", 404);
        }

        $retentionDays = $request->get('retentionDays');
        $options = MaintenanceOptions::fromRetentionDays(
            is_numeric($retentionDays) ? (int) $retentionDays : null,
        );

        // Lift limits so a full-tree preview scan can reach the web server's ~300s timeout.
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        return Response::json(self::previewTask($task, $options), 200);
    }

    /**
     * A placeholder card for a deferred task: no counts computed yet, flagged so the client
     * fetches the real preview lazily via {@see previewOne()}.
     *
     * @param MaintenanceTask $task the deferred task
     * @return array{key: string, label: string, description: string, items: int, bytes: int, humanBytes: string, sample: array<int, string>, error: bool, deferred: bool}
     */
    private static function deferredPlaceholder(MaintenanceTask $task): array
    {
        return [
            'key'         => $task->key(),
            'label'       => $task->label(),
            'description' => $task->description(),
            'items'       => 0,
            'bytes'       => 0,
            'humanBytes'  => MaintenanceFilesystem::humanBytes(0),
            'sample'      => [],
            'error'       => false,
            'deferred'    => true,
        ];
    }

    /**
     * Preview a single task, isolating failures so one bad task never breaks the dashboard.
     *
     * @param MaintenanceTask $task the task to preview
     * @param MaintenanceOptions $options shared options
     * @return array{key: string, label: string, description: string, items: int, bytes: int, humanBytes: string, sample: array<int, string>, error: bool, deferred: bool}
     */
    private static function previewTask(MaintenanceTask $task, MaintenanceOptions $options): array
    {
        try {
            $preview = $task->preview($options);
            $items = $preview->items;
            $bytes = $preview->bytes;
            $sample = $preview->sample;
            $error = false;
        } catch (Throwable) {
            $items = 0;
            $bytes = 0;
            $sample = [];
            $error = true;
        }

        return [
            'key'         => $task->key(),
            'label'       => $task->label(),
            'description' => $task->description(),
            'items'       => $items,
            'bytes'       => $bytes,
            'humanBytes'  => MaintenanceFilesystem::humanBytes($bytes),
            'sample'      => $sample,
            'error'       => $error,
            'deferred'    => false,
        ];
    }

    /**
     * Disk usage of the filesystem holding the site.
     *
     * @param App $kirby the Kirby app
     * @return array{freeBytes: int, totalBytes: int, usedPercent: int, freeHuman: string, totalHuman: string, note: string}
     */
    private static function diskUsage(App $kirby): array
    {
        $root = $kirby->root('index') ?? '.';
        $free = disk_free_space($root);
        $total = disk_total_space($root);

        return self::diskReport(
            is_float($free) ? (int) $free : 0,
            is_float($total) ? (int) $total : 0,
        );
    }

    /**
     * Assemble the disk-gauge report from raw byte figures. Pure (no filesystem or global
     * state) so the percentage maths and the reserved-blocks note stay unit-testable.
     *
     * `usedPercent` is `(total - free) / total`, where `free` is the space available to the
     * site; root-reserved blocks therefore count towards "used". {@see FREE_SPACE_NOTE}
     * explains why that can read higher than a host dashboard.
     *
     * @param int $freeBytes bytes available to the site (from {@see disk_free_space()})
     * @param int $totalBytes total filesystem bytes (from {@see disk_total_space()})
     * @return array{freeBytes: int, totalBytes: int, usedPercent: int, freeHuman: string, totalHuman: string, note: string}
     */
    public static function diskReport(int $freeBytes, int $totalBytes): array
    {
        $usedPercent = $totalBytes > 0 ? (int) round((($totalBytes - $freeBytes) / $totalBytes) * 100) : 0;

        return [
            'freeBytes'   => $freeBytes,
            'totalBytes'  => $totalBytes,
            'usedPercent' => $usedPercent,
            'freeHuman'   => MaintenanceFilesystem::humanBytes($freeBytes),
            'totalHuman'  => MaintenanceFilesystem::humanBytes($totalBytes),
            'note'        => self::FREE_SPACE_NOTE,
        ];
    }

    /**
     * Directory holding the per-task lock files.
     *
     * @param App $kirby the Kirby app
     * @return string
     */
    private static function lockDir(App $kirby): string
    {
        return ($kirby->root('logs') ?? sys_get_temp_dir()) . '/maintenance-locks';
    }

    /**
     * Whether the current Panel user is an administrator.
     *
     * @return bool
     */
    private static function isAdmin(): bool
    {
        try {
            return (new KirbyInternalHelper())->doesCurrentUserHaveRole('admin');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Build a JSON error response.
     *
     * @param string $message the error message
     * @param int $code the HTTP status code
     * @return Response
     */
    private static function error(string $message, int $code): Response
    {
        return Response::json(['error' => $message], $code);
    }
}
