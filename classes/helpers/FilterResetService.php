<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

/**
 * Handles the "Remove all filters" reset requested from the filter warning.
 *
 * When the current request carries the reset parameter, every persisted
 * filter value resolves to its fallback and its cookie is deleted, so the
 * list reverts to exactly the state a fresh visitor (with no cookies) sees.
 * Page-scoped and programmatic defaults are unaffected.
 *
 * @package BSBI\WebBase
 */
final readonly class FilterResetService
{
    /** Request parameter that triggers a filter reset */
    public const string RESET_PARAM = 'clearFilters';

    /**
     * @param bool $resetRequested Whether the current request asks for a filter reset
     */
    public function __construct(private bool $resetRequested)
    {
    }

    /**
     * Builds the service from the raw request parameter value.
     *
     * @param string|null $value The raw clearFilters request value
     * @return self
     */
    public static function fromRequestValue(?string $value): self
    {
        return new self($value === '1');
    }

    /**
     * @return bool True when the current request asks for a filter reset
     */
    public function isResetRequested(): bool
    {
        return $this->resetRequested;
    }

    /**
     * Resolves a persisted filter value: on a reset request the fallback is
     * returned and the deleter invoked (to expire the persisting cookie);
     * otherwise the persisted value is returned unchanged.
     *
     * @param string $persistedValue The value currently persisted (e.g. from a cookie)
     * @param string $fallback The default value the filter reverts to
     * @param callable(): void $deletePersistedValue Invoked on reset to remove the persisted value
     * @return string
     */
    public function resolve(string $persistedValue, string $fallback, callable $deletePersistedValue): string
    {
        if ($this->resetRequested) {
            $deletePersistedValue();
            return $fallback;
        }
        return $persistedValue;
    }
}
