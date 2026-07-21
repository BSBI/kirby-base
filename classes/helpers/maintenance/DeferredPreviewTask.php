<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers\maintenance;

/**
 * Marker for a {@see MaintenanceTask} whose {@see MaintenanceTask::preview()} is too expensive
 * to compute inline while the dashboard renders (e.g. the media cleanup walks the whole
 * `media/pages` tree).
 *
 * The Panel returns a placeholder for such tasks in the initial dashboard payload and fetches
 * their preview lazily via a dedicated, time-limit-lifted endpoint — so a slow scan never blocks
 * or times out the 30-second dashboard request.
 *
 * @package BSBI\WebBase\helpers\maintenance
 */
interface DeferredPreviewTask
{
}
