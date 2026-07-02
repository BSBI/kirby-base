<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\models;

use BSBI\WebBase\models\BaseFilter;
use BSBI\WebBase\models\SimpleFilter;
use PHPUnit\Framework\TestCase;

/**
 * Tests for BaseFilter::buildDescription() and the description exclusion
 * mechanism used by the "Results are being filtered" warning.
 *
 * Covers: keyword description, subclass describeActiveFilters() lines,
 * exclusions, idempotency, and the empty (unfiltered) case.
 */
final class BaseFilterBuildDescriptionTest extends TestCase
{
    /**
     * Create a BaseFilter subclass whose describeActiveFilters() returns fixed lines.
     *
     * @param string[] $lines
     * @return BaseFilter
     */
    private function createFilterWithLines(array $lines): BaseFilter
    {
        return new class ($lines) extends BaseFilter {
            /** @param string[] $lines */
            public function __construct(private readonly array $lines)
            {
            }

            /** @return string[] */
            protected function describeActiveFilters(): array
            {
                return $this->lines;
            }
        };
    }

    /**
     * An unfiltered instance builds an empty description.
     */
    public function testBuildDescriptionWithNoActiveFiltersIsEmpty(): void
    {
        $filter = new SimpleFilter();
        $filter->buildDescription();

        $this->assertFalse($filter->hasDescription());
    }

    /**
     * Keywords produce a description line without any subclass involvement.
     */
    public function testBuildDescriptionDescribesKeywords(): void
    {
        $filter = new SimpleFilter();
        $filter->setKeywords('orchid');
        $filter->buildDescription();

        $this->assertTrue($filter->hasDescription());
        $this->assertSame(['Keyword(s): orchid'], $filter->getDescription());
    }

    /**
     * Subclass describeActiveFilters() lines are appended after the keyword line.
     */
    public function testBuildDescriptionIncludesSubclassLines(): void
    {
        $filter = $this->createFilterWithLines(['Only in Wales', 'Only for activity Recording']);
        $filter->setKeywords('sedge');
        $filter->buildDescription();

        $this->assertSame(
            ['Keyword(s): sedge', 'Only in Wales', 'Only for activity Recording'],
            $filter->getDescription()
        );
    }

    /**
     * buildDescription() is idempotent: calling it twice must not duplicate lines.
     */
    public function testBuildDescriptionIsIdempotent(): void
    {
        $filter = $this->createFilterWithLines(['Only in Scotland']);
        $filter->buildDescription();
        $filter->buildDescription();

        $this->assertSame(['Only in Scotland'], $filter->getDescription());
    }

    /**
     * An excluded key suppresses the corresponding description line.
     */
    public function testKeywordsCanBeExcludedFromDescription(): void
    {
        $filter = new SimpleFilter();
        $filter->setKeywords('orchid');
        $filter->excludeFromDescription(BaseFilter::DESCRIBE_KEYWORDS);
        $filter->buildDescription();

        $this->assertFalse($filter->hasDescription());
    }

    /**
     * Exclusion keys default to not excluded and report correctly once set.
     */
    public function testIsExcludedFromDescription(): void
    {
        $filter = new SimpleFilter();

        $this->assertFalse($filter->isExcludedFromDescription('eventTypes'));

        $filter->excludeFromDescription('eventTypes', 'locationType');

        $this->assertTrue($filter->isExcludedFromDescription('eventTypes'));
        $this->assertTrue($filter->isExcludedFromDescription('locationType'));
        $this->assertFalse($filter->isExcludedFromDescription('dateRange'));
    }
}
