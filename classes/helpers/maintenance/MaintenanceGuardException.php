<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers\maintenance;

use RuntimeException;

/**
 * Thrown by {@see MaintenanceGuard::assertPermitted()} when a destructive maintenance task is
 * refused in the current environment. A distinct type so callers can catch guard refusals
 * specifically (and never mistake them for an ordinary task failure).
 *
 * @package BSBI\WebBase\helpers\maintenance
 */
final class MaintenanceGuardException extends RuntimeException
{
}
