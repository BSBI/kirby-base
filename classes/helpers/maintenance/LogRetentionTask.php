<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers\maintenance;

use DateTimeImmutable;
use Kirby\Cms\App;

/**
 * Generic maintenance task: prune Kirby's `*.log` files to a retention window.
 *
 * A thin Kirby adapter — it resolves the logs root from the app and delegates all logic
 * to the unit-tested {@see LogRetentionService}/{@see LogRetentionPolicy}. Blocking:
 * completes in a single (time-limit-lifted) request.
 *
 * @package BSBI\WebBase\helpers\maintenance
 */
final readonly class LogRetentionTask implements MaintenanceTask
{
    private LogRetentionService $service;

    /**
     * @param App $kirby the Kirby app, used only to resolve the logs root
     */
    public function __construct(private App $kirby)
    {
        $this->service = new LogRetentionService(new LogRetentionPolicy(new DateTimeImmutable('now')));
    }

    /**
     * @inheritDoc
     */
    public function key(): string
    {
        return 'logs';
    }

    /**
     * @inheritDoc
     */
    public function label(): string
    {
        return 'Log files';
    }

    /**
     * @inheritDoc
     */
    public function description(): string
    {
        return 'Trim site logs to the retention window; oversized undated logs are size-capped. '
            . 'The SQLite content indexes are never touched.';
    }

    /**
     * @inheritDoc
     */
    public function preview(MaintenanceOptions $options): MaintenancePreview
    {
        return $this->service->preview($this->logsDir(), $options);
    }

    /**
     * @inheritDoc
     */
    public function run(MaintenanceOptions $options, int $offset = 0, int $limit = 0): MaintenanceRunResult
    {
        return $this->service->run($this->logsDir(), $options);
    }

    /**
     * Absolute path to Kirby's logs directory.
     *
     * @return string
     */
    private function logsDir(): string
    {
        return $this->kirby->root('logs') ?? '';
    }
}
