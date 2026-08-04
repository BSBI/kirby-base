<?php
declare(strict_types=1);
namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\SearchIndexHelper;
use BSBI\WebBase\Testing\KirbyTestEnvironment;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Verifies that constructing the helper repeatedly reuses one connection.
 *
 * The page.delete:before hook constructs one per deleted page, so without reuse
 * a bulk delete opened a fresh SQLite connection — plus a schema query and two
 * table checks — for every page it touched.
 */
final class SearchIndexHelperConnectionTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        KirbyTestEnvironment::boot('kirby-base-search-reuse');
    }

    protected function setUp(): void
    {
        SearchIndexHelper::resetConnections();
    }

    private function connectionOf(SearchIndexHelper $helper): object
    {
        $property = new ReflectionProperty(SearchIndexHelper::class, 'database');

        return $property->getValue($helper);
    }

    public function testASecondInstanceReusesTheSameConnection(): void
    {
        $first = $this->connectionOf(new SearchIndexHelper());
        $second = $this->connectionOf(new SearchIndexHelper());

        $this->assertSame($first, $second, 'each instance opened its own connection');
    }

    public function testResettingForcesAFreshConnection(): void
    {
        $first = $this->connectionOf(new SearchIndexHelper());
        SearchIndexHelper::resetConnections();
        $second = $this->connectionOf(new SearchIndexHelper());

        $this->assertNotSame($first, $second, 'reset did not drop the cached connection');
    }
}
