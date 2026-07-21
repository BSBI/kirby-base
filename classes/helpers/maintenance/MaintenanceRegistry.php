<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers\maintenance;

/**
 * Static registry of maintenance tasks, keyed by {@see MaintenanceTask::key()}.
 *
 * kirby-base registers its generic tasks (logs, cache); consuming sites register their
 * own (e.g. bsbi-web's import cleanup) into the same registry, so the Panel area lists
 * every task uniformly without kirby-base knowing about site-specific ones.
 *
 * Mirrors {@see \BSBI\WebBase\helpers\ContentIndexRegistry}.
 *
 * @package BSBI\WebBase\helpers\maintenance
 */
final class MaintenanceRegistry
{
    /** @var array<string, MaintenanceTask> */
    private static array $tasks = [];

    /**
     * Register (or replace) a task. Later registrations of the same key win, so a site
     * can override a generic task if it needs to.
     *
     * @param MaintenanceTask $task the task to register
     * @return void
     */
    public static function register(MaintenanceTask $task): void
    {
        self::$tasks[$task->key()] = $task;
    }

    /**
     * Look up a task by key.
     *
     * @param string $key the task key
     * @return MaintenanceTask|null the task, or null if not registered
     */
    public static function get(string $key): ?MaintenanceTask
    {
        return self::$tasks[$key] ?? null;
    }

    /**
     * All registered tasks, in registration order.
     *
     * @return array<string, MaintenanceTask>
     */
    public static function all(): array
    {
        return self::$tasks;
    }

    /**
     * Remove all registered tasks. Intended for test isolation.
     *
     * @return void
     */
    public static function clear(): void
    {
        self::$tasks = [];
    }
}
