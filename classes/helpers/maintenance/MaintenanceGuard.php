<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers\maintenance;

/**
 * Safety contract for destructive maintenance tasks that must only ever run in certain
 * environments (e.g. a blanket media wipe that is acceptable on staging but catastrophic
 * on live).
 *
 * A guard is consulted twice, deliberately: {@see isPermitted()} at *registration* time (so a
 * dangerous task never even appears where it must not run), and {@see assertPermitted()} at the
 * top of every {@see MaintenanceTask::preview()} / {@see MaintenanceTask::run()} as a
 * belt-and-braces *runtime* re-assertion. A single misconfiguration must not be able to fire a
 * destructive task in the wrong place.
 *
 * The contract is intentionally site-agnostic: kirby-base ships a generic
 * {@see EnvironmentGuard}; a consuming site supplies the actual environment/marker policy.
 *
 * @package BSBI\WebBase\helpers\maintenance
 */
interface MaintenanceGuard
{
    /**
     * Whether the guarded action is permitted in the current environment. Non-throwing, for the
     * registration gate (`if ($guard->isPermitted()) { register... }`).
     *
     * @return bool true only when it is safe to expose/run the guarded task
     */
    public function isPermitted(): bool;

    /**
     * Re-assert that the guarded action is permitted, throwing if not. Called at the top of a
     * destructive task's preview()/run() so no single registration mistake can fire it.
     *
     * @return void
     * @throws MaintenanceGuardException if the action is not permitted in the current environment
     */
    public function assertPermitted(): void;
}
