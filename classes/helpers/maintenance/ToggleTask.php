<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers\maintenance;

/**
 * A task whose card has a checkbox ({@see MaintenanceToggle}), such as "Dry run".
 * Its state is passed to preview() and run() as {@see MaintenanceOptions::$toggle}.
 *
 * @package BSBI\WebBase\helpers\maintenance
 */
interface ToggleTask
{
    /**
     * The checkbox, worked out when the dashboard loads (its wording may depend on
     * the task's state, e.g. the last dry run's counts).
     */
    public function toggle(): MaintenanceToggle;
}
