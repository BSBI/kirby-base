<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers\maintenance;

/**
 * Generic environment-based guard for destructive maintenance tasks.
 *
 * Permits a guarded action only when BOTH hold:
 *   1. the current environment is on a **positive allow-list** of non-production environments
 *      (e.g. `['staging', 'dev']`). This is deliberately an allow-list, never `!== 'live'`: any
 *      unrecognised or mistyped value — including the production default and the empty string —
 *      must fail closed rather than accidentally arm the tools.
 *   2. the application path contains **none** of the denied markers (defence in depth). This belt
 *      can only ever *refuse*, never permit: if a stray config marks the live box as 'staging',
 *      the live path marker still catches it. An empty path (or empty marker) simply skips the
 *      belt, leaving the decision to the allow-list.
 *
 * All inputs are injected, so the full truth table is unit-testable without touching the real
 * environment. A consuming site wires the environment value (e.g. from `option('environment.type')`)
 * and its host-specific path markers; the logic here stays site-agnostic.
 *
 * @package BSBI\WebBase\helpers\maintenance
 */
final readonly class EnvironmentGuard implements MaintenanceGuard
{
    /**
     * @param string $environment the current environment identifier (e.g. 'live'/'staging'/'dev')
     * @param array<int, string> $permittedEnvironments the positive allow-list of environments in
     *        which the guarded action may run
     * @param string $indexPath absolute application path checked against $deniedPathMarkers; empty
     *        skips the path belt
     * @param array<int, string> $deniedPathMarkers substrings that, if present in $indexPath, force
     *        a refusal regardless of $environment (empty strings are ignored)
     */
    public function __construct(
        private string $environment,
        private array $permittedEnvironments,
        private string $indexPath = '',
        private array $deniedPathMarkers = [],
    ) {
    }

    /**
     * @inheritDoc
     */
    public function isPermitted(): bool
    {
        return $this->denialReason() === null;
    }

    /**
     * @inheritDoc
     */
    public function assertPermitted(): void
    {
        $reason = $this->denialReason();
        if ($reason !== null) {
            throw new MaintenanceGuardException('Destructive maintenance refused: ' . $reason . '.');
        }
    }

    /**
     * Why the action is refused, or null if it is permitted. Centralised so {@see isPermitted()}
     * and {@see assertPermitted()} can never disagree, and the exception carries a useful reason.
     *
     * @return string|null the human-readable refusal reason, or null when permitted
     */
    private function denialReason(): ?string
    {
        if (!in_array($this->environment, $this->permittedEnvironments, true)) {
            return sprintf(
                "environment '%s' is not in the permitted list [%s]",
                $this->environment,
                implode(', ', $this->permittedEnvironments),
            );
        }

        if ($this->indexPath !== '') {
            foreach ($this->deniedPathMarkers as $marker) {
                if ($marker !== '' && str_contains($this->indexPath, $marker)) {
                    return sprintf("application path contains the denied marker '%s'", $marker);
                }
            }
        }

        return null;
    }
}
