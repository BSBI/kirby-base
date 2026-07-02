<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\FilterResetService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for FilterResetService — the "Remove all filters" reset logic.
 *
 * When a reset is requested (via the clearFilters request parameter) every
 * persisted filter value resolves to its fallback and the persisted cookie
 * is deleted, so the list reverts to the same state a fresh visitor sees.
 */
final class FilterResetServiceTest extends TestCase
{
    public function testResetRequestedFromRequestValue(): void
    {
        $this->assertTrue(FilterResetService::fromRequestValue('1')->isResetRequested());
        $this->assertFalse(FilterResetService::fromRequestValue('0')->isResetRequested());
        $this->assertFalse(FilterResetService::fromRequestValue('')->isResetRequested());
        $this->assertFalse(FilterResetService::fromRequestValue(null)->isResetRequested());
    }

    public function testResolveReturnsPersistedValueWhenNoReset(): void
    {
        $service = new FilterResetService(false);
        $deleted = false;

        $value = $service->resolve('Field meeting', '', function () use (&$deleted): void {
            $deleted = true;
        });

        $this->assertSame('Field meeting', $value);
        $this->assertFalse($deleted);
    }

    public function testResolveReturnsFallbackAndDeletesWhenReset(): void
    {
        $service = new FilterResetService(true);
        $deleted = false;

        $value = $service->resolve('Field meeting', '', function () use (&$deleted): void {
            $deleted = true;
        });

        $this->assertSame('', $value);
        $this->assertTrue($deleted);
    }

    public function testResolveHonoursCustomFallbackOnReset(): void
    {
        $service = new FilterResetService(true);

        $value = $service->resolve('50', '100', static function (): void {
        });

        $this->assertSame('100', $value);
    }

    public function testResetParamName(): void
    {
        $this->assertSame('clearFilters', FilterResetService::RESET_PARAM);
    }
}
