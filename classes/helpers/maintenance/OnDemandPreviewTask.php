<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers\maintenance;

/**
 * Marker for a {@see MaintenanceTask} whose preview should run only when an editor
 * asks for it — e.g. one that calls an outside service, which shouldn't happen just
 * because someone opened the Maintenance page.
 *
 * Like a {@see DeferredPreviewTask}, the dashboard carries a placeholder; unlike one,
 * the Panel doesn't fetch the preview by itself. The card shows a "Preview" button,
 * and the Panel keeps "Run" disabled until a preview has loaded. (That's a Panel
 * guard, not a server one: the run endpoint doesn't check for a preview.)
 *
 * @package BSBI\WebBase\helpers\maintenance
 */
interface OnDemandPreviewTask extends DeferredPreviewTask
{
}
