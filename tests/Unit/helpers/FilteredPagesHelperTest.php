<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\FilteredPagesHelper;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for FilteredPagesHelper pure methods.
 *
 * Only the pure static methods (applyFilters, applySearch, applySort, paginate)
 * are tested here — they have no Kirby dependency.  The Kirby-dependent methods
 * (getOptions, getResults) require a running Kirby instance and are covered by
 * integration tests at the site level.
 */
final class FilteredPagesHelperTest extends TestCase
{
    // ── Fixtures ──────────────────────────────────────────────────────────

    /**
     * Returns a minimal set of page data arrays that cover the main filter paths.
     *
     * @return array<int, array<string, mixed>>
     */
    private function makePages(): array
    {
        return [
            [
                'id'       => 'events/mountain-walk',
                'title'    => 'Mountain Walk',
                'status'   => 'listed',
                'panelUrl' => '/panel/pages/events+mountain-walk',
                'filterValues' => [
                    'activities' => ['activities/botany', 'activities/walking'],
                    'projects'   => ['projects/atlas'],
                ],
                'displayValues' => [
                    'title'     => 'Mountain Walk',
                    'status'    => 'listed',
                    'startDate' => '2025-06-15',
                ],
            ],
            [
                'id'       => 'events/river-survey',
                'title'    => 'River Survey',
                'status'   => 'listed',
                'panelUrl' => '/panel/pages/events+river-survey',
                'filterValues' => [
                    'activities' => ['activities/surveying'],
                    'projects'   => ['projects/atlas', 'projects/bryophytes'],
                ],
                'displayValues' => [
                    'title'     => 'River Survey',
                    'status'    => 'listed',
                    'startDate' => '2025-07-20',
                ],
            ],
            [
                'id'       => 'events/autumn-foray',
                'title'    => 'Autumn Foray',
                'status'   => 'draft',
                'panelUrl' => '/panel/pages/events+autumn-foray',
                'filterValues' => [
                    'activities' => ['activities/botany', 'activities/surveying'],
                    'projects'   => ['projects/bryophytes'],
                ],
                'displayValues' => [
                    'title'     => 'Autumn Foray',
                    'status'    => 'draft',
                    'startDate' => '2025-09-10',
                ],
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function makeFilterDefs(): array
    {
        return [
            'activities' => ['type' => 'pages', 'collection' => 'activities'],
            'projects'   => ['type' => 'pages', 'collection' => 'projectsAll'],
        ];
    }

    // ── applyFilters ──────────────────────────────────────────────────────

    public function testApplyFilters_emptyActive_returnsAllPages(): void
    {
        $pages  = $this->makePages();
        $result = FilteredPagesHelper::applyFilters($pages, $this->makeFilterDefs(), []);
        $this->assertCount(3, $result);
    }

    public function testApplyFilters_byActivity_returnsMatchingPages(): void
    {
        $pages  = $this->makePages();
        $result = FilteredPagesHelper::applyFilters(
            $pages,
            $this->makeFilterDefs(),
            ['activities' => 'activities/botany']
        );
        $this->assertCount(2, $result);
        $titles = array_column($result, 'title');
        $this->assertContains('Mountain Walk', $titles);
        $this->assertContains('Autumn Foray', $titles);
    }

    public function testApplyFilters_byProject_returnsMatchingPages(): void
    {
        $pages  = $this->makePages();
        $result = FilteredPagesHelper::applyFilters(
            $pages,
            $this->makeFilterDefs(),
            ['projects' => 'projects/bryophytes']
        );
        $this->assertCount(2, $result);
        $titles = array_column($result, 'title');
        $this->assertContains('River Survey', $titles);
        $this->assertContains('Autumn Foray', $titles);
    }

    public function testApplyFilters_combinedFilters_returnsIntersection(): void
    {
        // Both River Survey (surveying + bryophytes) and Autumn Foray (surveying + bryophytes) match.
        $pages  = $this->makePages();
        $result = FilteredPagesHelper::applyFilters(
            $pages,
            $this->makeFilterDefs(),
            [
                'activities' => 'activities/surveying',
                'projects'   => 'projects/bryophytes',
            ]
        );
        $this->assertCount(2, $result);
        $titles = array_column($result, 'title');
        $this->assertContains('River Survey', $titles);
        $this->assertContains('Autumn Foray', $titles);
    }

    public function testApplyFilters_noMatch_returnsEmpty(): void
    {
        $pages  = $this->makePages();
        $result = FilteredPagesHelper::applyFilters(
            $pages,
            $this->makeFilterDefs(),
            ['activities' => 'activities/nonexistent']
        );
        $this->assertCount(0, $result);
    }

    public function testApplyFilters_emptyStringValue_isIgnored(): void
    {
        $pages  = $this->makePages();
        $result = FilteredPagesHelper::applyFilters(
            $pages,
            $this->makeFilterDefs(),
            ['activities' => '']
        );
        $this->assertCount(3, $result);
    }

    public function testApplyFilters_unknownFilterField_isIgnored(): void
    {
        $pages  = $this->makePages();
        $result = FilteredPagesHelper::applyFilters(
            $pages,
            $this->makeFilterDefs(),
            ['nonexistent' => 'some-value']
        );
        $this->assertCount(3, $result);
    }

    // ── applyFilters — siteStructure type ────────────────────────────────

    /** @return array<int, array<string, mixed>> */
    private function makeSiteStructurePages(): array
    {
        return [
            [
                'id'            => 'news/recording-news',
                'title'         => 'Recording News',
                'status'        => 'listed',
                'panelUrl'      => '/panel/pages/news+recording-news',
                'filterValues'  => ['areasOfWork' => ['Recording', 'Conservation']],
                'displayValues' => ['title' => 'Recording News', 'status' => 'listed'],
            ],
            [
                'id'            => 'news/conservation-update',
                'title'         => 'Conservation Update',
                'status'        => 'listed',
                'panelUrl'      => '/panel/pages/news+conservation-update',
                'filterValues'  => ['areasOfWork' => ['Conservation']],
                'displayValues' => ['title' => 'Conservation Update', 'status' => 'listed'],
            ],
            [
                'id'            => 'news/general-news',
                'title'         => 'General News',
                'status'        => 'listed',
                'panelUrl'      => '/panel/pages/news+general-news',
                'filterValues'  => ['areasOfWork' => []],
                'displayValues' => ['title' => 'General News', 'status' => 'listed'],
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function makeSiteStructureFilterDefs(): array
    {
        return [
            'areasOfWork' => ['type' => 'siteStructure', 'siteField' => 'areasOfWork', 'valueField' => 'name'],
        ];
    }

    public function testApplyFilters_siteStructure_returnsMatchingPages(): void
    {
        $pages  = $this->makeSiteStructurePages();
        $result = FilteredPagesHelper::applyFilters(
            $pages,
            $this->makeSiteStructureFilterDefs(),
            ['areasOfWork' => 'Recording']
        );
        $this->assertCount(1, $result);
        $this->assertSame('Recording News', $result[0]['title']);
    }

    public function testApplyFilters_siteStructure_multipleMatchingPages(): void
    {
        $pages  = $this->makeSiteStructurePages();
        $result = FilteredPagesHelper::applyFilters(
            $pages,
            $this->makeSiteStructureFilterDefs(),
            ['areasOfWork' => 'Conservation']
        );
        $this->assertCount(2, $result);
        $titles = array_column($result, 'title');
        $this->assertContains('Recording News',     $titles);
        $this->assertContains('Conservation Update', $titles);
    }

    public function testApplyFilters_siteStructure_emptyValue_returnsAll(): void
    {
        $pages  = $this->makeSiteStructurePages();
        $result = FilteredPagesHelper::applyFilters(
            $pages,
            $this->makeSiteStructureFilterDefs(),
            ['areasOfWork' => '']
        );
        $this->assertCount(3, $result);
    }

    public function testApplyFilters_siteStructure_noMatch_returnsEmpty(): void
    {
        $pages  = $this->makeSiteStructurePages();
        $result = FilteredPagesHelper::applyFilters(
            $pages,
            $this->makeSiteStructureFilterDefs(),
            ['areasOfWork' => 'Nonexistent']
        );
        $this->assertCount(0, $result);
    }

    // ── applyFilters: dateYear ────────────────────────────────────────────

    /** @return array<int, array<string, mixed>> */
    private function makeDateYearPages(): array
    {
        return [
            [
                'id'            => 'events/summer-foray-2024',
                'title'         => 'Summer Foray 2024',
                'status'        => 'listed',
                'panelUrl'      => '/panel/pages/events+summer-foray-2024',
                'filterValues'  => ['startYear' => ['2024']],
                'displayValues' => ['title' => 'Summer Foray 2024', 'status' => 'listed'],
            ],
            [
                'id'            => 'events/winter-meeting-2024',
                'title'         => 'Winter Meeting 2024',
                'status'        => 'listed',
                'panelUrl'      => '/panel/pages/events+winter-meeting-2024',
                'filterValues'  => ['startYear' => ['2024']],
                'displayValues' => ['title' => 'Winter Meeting 2024', 'status' => 'listed'],
            ],
            [
                'id'            => 'events/spring-walk-2025',
                'title'         => 'Spring Walk 2025',
                'status'        => 'listed',
                'panelUrl'      => '/panel/pages/events+spring-walk-2025',
                'filterValues'  => ['startYear' => ['2025']],
                'displayValues' => ['title' => 'Spring Walk 2025', 'status' => 'listed'],
            ],
            [
                'id'            => 'events/undated-event',
                'title'         => 'Undated Event',
                'status'        => 'draft',
                'panelUrl'      => '/panel/pages/events+undated-event',
                'filterValues'  => ['startYear' => []],
                'displayValues' => ['title' => 'Undated Event', 'status' => 'draft'],
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function makeDateYearFilterDefs(): array
    {
        return [
            'startYear' => ['type' => 'dateYear', 'field' => 'startDate'],
        ];
    }

    public function testApplyFilters_dateYear_returnsMatchingPages(): void
    {
        $pages  = $this->makeDateYearPages();
        $result = FilteredPagesHelper::applyFilters(
            $pages,
            $this->makeDateYearFilterDefs(),
            ['startYear' => '2024']
        );
        $this->assertCount(2, $result);
        $titles = array_column($result, 'title');
        $this->assertContains('Summer Foray 2024',  $titles);
        $this->assertContains('Winter Meeting 2024', $titles);
    }

    public function testApplyFilters_dateYear_singleYear_returnsOnePage(): void
    {
        $pages  = $this->makeDateYearPages();
        $result = FilteredPagesHelper::applyFilters(
            $pages,
            $this->makeDateYearFilterDefs(),
            ['startYear' => '2025']
        );
        $this->assertCount(1, $result);
        $this->assertSame('Spring Walk 2025', $result[0]['title']);
    }

    public function testApplyFilters_dateYear_emptyValue_returnsAll(): void
    {
        $pages  = $this->makeDateYearPages();
        $result = FilteredPagesHelper::applyFilters(
            $pages,
            $this->makeDateYearFilterDefs(),
            ['startYear' => '']
        );
        $this->assertCount(4, $result);
    }

    public function testApplyFilters_dateYear_noMatch_returnsEmpty(): void
    {
        $pages  = $this->makeDateYearPages();
        $result = FilteredPagesHelper::applyFilters(
            $pages,
            $this->makeDateYearFilterDefs(),
            ['startYear' => '2099']
        );
        $this->assertCount(0, $result);
    }

    public function testApplyFilters_dateYear_undatedPageExcluded(): void
    {
        $pages  = $this->makeDateYearPages();
        $result = FilteredPagesHelper::applyFilters(
            $pages,
            $this->makeDateYearFilterDefs(),
            ['startYear' => '2024']
        );
        $titles = array_column($result, 'title');
        $this->assertNotContains('Undated Event', $titles);
    }

    // ── applySearch ───────────────────────────────────────────────────────

    public function testApplySearch_emptyString_returnsAllPages(): void
    {
        $pages  = $this->makePages();
        $result = FilteredPagesHelper::applySearch($pages, '');
        $this->assertCount(3, $result);
    }

    public function testApplySearch_matchingSubstring_returnsMatch(): void
    {
        $pages  = $this->makePages();
        $result = FilteredPagesHelper::applySearch($pages, 'river');
        $this->assertCount(1, $result);
        $this->assertSame('River Survey', $result[0]['title']);
    }

    public function testApplySearch_caseInsensitive(): void
    {
        $pages  = $this->makePages();
        $result = FilteredPagesHelper::applySearch($pages, 'MOUNTAIN');
        $this->assertCount(1, $result);
        $this->assertSame('Mountain Walk', $result[0]['title']);
    }

    public function testApplySearch_noMatch_returnsEmpty(): void
    {
        $pages  = $this->makePages();
        $result = FilteredPagesHelper::applySearch($pages, 'zzznomatch');
        $this->assertCount(0, $result);
    }

    public function testApplySearch_partialMatch_returnsMultiple(): void
    {
        $pages  = $this->makePages();
        // 'a' appears in 'Mountain Walk' and 'Autumn Foray' but not 'River Survey'
        $result = FilteredPagesHelper::applySearch($pages, 'a');
        $this->assertCount(2, $result);
        $titles = array_column($result, 'title');
        $this->assertContains('Mountain Walk', $titles);
        $this->assertContains('Autumn Foray',  $titles);
    }

    // ── applySort ─────────────────────────────────────────────────────────

    public function testApplySort_byTitleAsc_returnsAlphabeticalOrder(): void
    {
        $pages  = $this->makePages();
        $result = FilteredPagesHelper::applySort($pages, 'title', 'asc');
        $this->assertSame('Autumn Foray',  $result[0]['title']);
        $this->assertSame('Mountain Walk', $result[1]['title']);
        $this->assertSame('River Survey',  $result[2]['title']);
    }

    public function testApplySort_byTitleDesc_returnsReverseAlphabeticalOrder(): void
    {
        $pages  = $this->makePages();
        $result = FilteredPagesHelper::applySort($pages, 'title', 'desc');
        $this->assertSame('River Survey',  $result[0]['title']);
        $this->assertSame('Mountain Walk', $result[1]['title']);
        $this->assertSame('Autumn Foray',  $result[2]['title']);
    }

    public function testApplySort_byStartDateAsc_returnsChronologicalOrder(): void
    {
        $pages  = $this->makePages();
        $result = FilteredPagesHelper::applySort($pages, 'startDate', 'asc');
        $this->assertSame('Mountain Walk', $result[0]['title']); // 2025-06-15
        $this->assertSame('River Survey',  $result[1]['title']); // 2025-07-20
        $this->assertSame('Autumn Foray',  $result[2]['title']); // 2025-09-10
    }

    public function testApplySort_byStartDateDesc_returnsReverseChronologicalOrder(): void
    {
        $pages  = $this->makePages();
        $result = FilteredPagesHelper::applySort($pages, 'startDate', 'desc');
        $this->assertSame('Autumn Foray',  $result[0]['title']); // 2025-09-10
        $this->assertSame('River Survey',  $result[1]['title']); // 2025-07-20
        $this->assertSame('Mountain Walk', $result[2]['title']); // 2025-06-15
    }

    public function testApplySort_byTopLevelField_status(): void
    {
        $pages  = $this->makePages();
        $result = FilteredPagesHelper::applySort($pages, 'status', 'asc');
        // 'draft' < 'listed' lexicographically
        $this->assertSame('Autumn Foray', $result[0]['title']);
    }

    // ── paginate ──────────────────────────────────────────────────────────

    public function testPaginate_firstPage_returnsCorrectSlice(): void
    {
        $pages  = $this->makePages();
        $result = FilteredPagesHelper::paginate($pages, 1, 2);
        $this->assertSame(3, $result['total']);
        $this->assertSame(2, $result['totalPages']);
        $this->assertSame(1, $result['page']);
        $this->assertCount(2, $result['items']);
        $this->assertSame('Mountain Walk', $result['items'][0]['title']);
        $this->assertSame('River Survey',  $result['items'][1]['title']);
    }

    public function testPaginate_secondPage_returnsRemainder(): void
    {
        $pages  = $this->makePages();
        $result = FilteredPagesHelper::paginate($pages, 2, 2);
        $this->assertCount(1, $result['items']);
        $this->assertSame('Autumn Foray', $result['items'][0]['title']);
    }

    public function testPaginate_pageSizeLargerThanTotal_returnsSinglePage(): void
    {
        $pages  = $this->makePages();
        $result = FilteredPagesHelper::paginate($pages, 1, 50);
        $this->assertSame(1, $result['totalPages']);
        $this->assertCount(3, $result['items']);
    }

    public function testPaginate_emptyInput_returnsZeroTotals(): void
    {
        $result = FilteredPagesHelper::paginate([], 1, 25);
        $this->assertSame(0, $result['total']);
        $this->assertSame(0, $result['totalPages']);
        $this->assertCount(0, $result['items']);
    }

    public function testPaginate_totalPagesRoundsUp(): void
    {
        // 5 items, 2 per page → 3 pages
        $pages  = array_fill(0, 5, $this->makePages()[0]);
        $result = FilteredPagesHelper::paginate($pages, 1, 2);
        $this->assertSame(3, $result['totalPages']);
    }

    // ── combined pipeline ─────────────────────────────────────────────────

    public function testCombinedPipeline_filterSearchSortPaginate(): void
    {
        $pages = $this->makePages();

        // Filter: botany activities (Mountain Walk + Autumn Foray)
        $pages = FilteredPagesHelper::applyFilters(
            $pages,
            $this->makeFilterDefs(),
            ['activities' => 'activities/botany']
        );
        // Search: 'mountain' → Mountain Walk only
        $pages = FilteredPagesHelper::applySearch($pages, 'mountain');
        $pages = FilteredPagesHelper::applySort($pages, 'title', 'asc');
        $result = FilteredPagesHelper::paginate($pages, 1, 25);

        $this->assertSame(1, $result['total']);
        $this->assertSame('Mountain Walk', $result['items'][0]['title']);
    }

    // ── decodeJsonParam ───────────────────────────────────────────────────

    public function testDecodeJsonParam_validObject_returnsArray(): void
    {
        $this->assertSame(
            ['committeeMeetingType' => 'Board of Trustees'],
            FilteredPagesHelper::decodeJsonParam('{"committeeMeetingType":"Board of Trustees"}')
        );
    }

    public function testDecodeJsonParam_valueContainingAmpersand_returnsArray(): void
    {
        $this->assertSame(
            ['committeeMeetingType' => 'Events & Communications'],
            FilteredPagesHelper::decodeJsonParam('{"committeeMeetingType":"Events & Communications"}')
        );
    }

    public function testDecodeJsonParam_emptyObject_returnsEmptyArray(): void
    {
        $this->assertSame([], FilteredPagesHelper::decodeJsonParam('{}'));
    }

    public function testDecodeJsonParam_emptyArray_returnsEmptyArray(): void
    {
        $this->assertSame([], FilteredPagesHelper::decodeJsonParam('[]'));
    }

    /**
     * An unencoded '&' in a filter value used to split the query string, so the
     * server saw a truncated 'active' parameter. json_decode() returned null and
     * the old '?? []' fallback turned that into "no filters", silently listing
     * every page. Corrupt input must be distinguishable from "nothing selected".
     */
    public function testDecodeJsonParam_truncatedAtUnencodedAmpersand_returnsNull(): void
    {
        $this->assertNull(FilteredPagesHelper::decodeJsonParam('{"committeeMeetingType":"Events '));
    }

    public function testDecodeJsonParam_malformedJson_returnsNull(): void
    {
        $this->assertNull(FilteredPagesHelper::decodeJsonParam('not json at all'));
    }

    public function testDecodeJsonParam_scalarJson_returnsNull(): void
    {
        $this->assertNull(FilteredPagesHelper::decodeJsonParam('"a string"'));
    }

    public function testDecodeJsonParam_emptyString_returnsEmptyArray(): void
    {
        // A missing parameter is "nothing selected", not corruption.
        $this->assertSame([], FilteredPagesHelper::decodeJsonParam(''));
    }
}
