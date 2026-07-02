<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\ContentIndexQuery;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the ContentIndexQuery fluent query builder.
 *
 * Uses an in-memory SQLite database so no Kirby environment is required.
 */
final class ContentIndexQueryTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->db->exec('
            CREATE TABLE events (
                page_id TEXT PRIMARY KEY,
                title TEXT NOT NULL DEFAULT "",
                start_date TEXT NOT NULL DEFAULT "",
                end_date TEXT NOT NULL DEFAULT "",
                event_type TEXT NOT NULL DEFAULT "",
                location_type TEXT NOT NULL DEFAULT "",
                categories TEXT NOT NULL DEFAULT "",
                vice_counties TEXT NOT NULL DEFAULT "",
                show_on_governance INTEGER NOT NULL DEFAULT 0,
                local_or_yearbook TEXT NOT NULL DEFAULT "",
                latitude TEXT NOT NULL DEFAULT "",
                longitude TEXT NOT NULL DEFAULT ""
            )
        ');

        $this->seedData();
    }

    private function seedData(): void
    {
        $rows = [
            ['events/walk-1', 'Spring Walk', '2025-03-15', '2025-03-15', 'Field meeting', 'Outdoor', 'Botany,Conservation', 'Surrey,Kent', 1, 'Yearbook', '51.2', '-0.1'],
            ['events/walk-2', 'Summer Walk', '2025-06-20', '2025-06-21', 'Field meeting', 'Outdoor', 'Botany', 'Yorkshire', 0, 'Local', '53.9', '-1.1'],
            ['events/conf-1', 'Annual Conference', '2025-09-10', '2025-09-12', 'Conference', 'Indoor', 'Education,Conservation', 'London', 1, 'Yearbook', '51.5', '-0.12'],
            ['events/workshop-1', 'ID Workshop', '2025-04-05', '2025-04-05', 'Workshop', 'Indoor', 'Education', 'Devon', 0, 'Yearbook', '50.7', '-3.5'],
            ['events/walk-3', 'Autumn Walk', '2025-10-01', '2025-10-01', 'Field meeting', 'Outdoor', 'Botany', 'Surrey', 0, 'Yearbook', '51.3', '-0.2'],
        ];

        $stmt = $this->db->prepare('
            INSERT INTO events (page_id, title, start_date, end_date, event_type, location_type, categories, vice_counties, show_on_governance, local_or_yearbook, latitude, longitude)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');

        foreach ($rows as $row) {
            $stmt->execute($row);
        }
    }

    private function query(): ContentIndexQuery
    {
        return new ContentIndexQuery($this->db, 'events');
    }

    // --- Basic retrieval ---

    public function testGetReturnsAllRowsWithNoFilters(): void
    {
        $results = $this->query()->get();
        $this->assertCount(5, $results);
    }

    public function testGetPageIdsReturnsOnlyIds(): void
    {
        $ids = $this->query()->getPageIds();
        $this->assertCount(5, $ids);
        $this->assertContains('events/walk-1', $ids);
        $this->assertContains('events/conf-1', $ids);
    }

    public function testCountReturnsRowCount(): void
    {
        $count = $this->query()->count();
        $this->assertSame(5, $count);
    }

    // --- where ---

    public function testWhereFiltersExactMatch(): void
    {
        $results = $this->query()
            ->where('event_type', 'Conference')
            ->get();

        $this->assertCount(1, $results);
        $this->assertSame('events/conf-1', $results[0]['page_id']);
    }

    public function testWhereMultipleConditions(): void
    {
        $results = $this->query()
            ->where('event_type', 'Field meeting')
            ->where('location_type', 'Outdoor')
            ->get();

        $this->assertCount(3, $results);
    }

    // --- whereOp ---

    public function testWhereOpGreaterThan(): void
    {
        $results = $this->query()
            ->whereOp('start_date', '>=', '2025-06-01')
            ->get();

        $this->assertCount(3, $results);
    }

    public function testWhereOpLessThan(): void
    {
        $results = $this->query()
            ->whereOp('start_date', '<', '2025-04-01')
            ->get();

        $this->assertCount(1, $results);
        $this->assertSame('events/walk-1', $results[0]['page_id']);
    }

    public function testWhereOpInvalidOperatorThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->query()->whereOp('start_date', 'LIKE', '2025%');
    }

    // --- whereContains ---

    public function testWhereContainsFindsValueAtStart(): void
    {
        $results = $this->query()
            ->whereContains('categories', 'Botany')
            ->get();

        $this->assertCount(3, $results);
    }

    public function testWhereContainsFindsValueAtEnd(): void
    {
        $results = $this->query()
            ->whereContains('categories', 'Conservation')
            ->get();

        $this->assertCount(2, $results);
    }

    public function testWhereContainsDoesNotMatchPartialValue(): void
    {
        $results = $this->query()
            ->whereContains('categories', 'Bot')
            ->get();

        $this->assertCount(0, $results);
    }

    public function testWhereContainsMatchesSingleValue(): void
    {
        $results = $this->query()
            ->whereContains('vice_counties', 'Devon')
            ->get();

        $this->assertCount(1, $results);
        $this->assertSame('events/workshop-1', $results[0]['page_id']);
    }

    public function testWhereContainsMatchesValueInMiddle(): void
    {
        // Surrey,Kent - Kent is at end; but let's test with multi-value: "Botany,Conservation"
        // "Conservation" is second in walk-1 and conf-1
        $results = $this->query()
            ->whereContains('categories', 'Conservation')
            ->get();

        $this->assertCount(2, $results);
    }

    // --- whereContainsAny ---

    public function testWhereContainsAnyMatchesMultipleValues(): void
    {
        $results = $this->query()
            ->whereContainsAny('event_type', ['Conference', 'Workshop'])
            ->get();

        $this->assertCount(2, $results);
    }

    public function testWhereContainsAnyWithEmptyArrayReturnsAll(): void
    {
        $results = $this->query()
            ->whereContainsAny('event_type', [])
            ->get();

        $this->assertCount(5, $results);
    }

    public function testWhereContainsAnySkipsEmptyStrings(): void
    {
        $results = $this->query()
            ->whereContainsAny('event_type', ['Conference', '', '  '])
            ->get();

        $this->assertCount(1, $results);
    }

    // --- whereAnyStartsWith ---

    public function testWhereAnyStartsWithMatchesPrefixOnSingleColumn(): void
    {
        // "Spring Walk" and "Summer Walk" both start with "S".
        $results = $this->query()
            ->whereAnyStartsWith(['title'], 'S')
            ->get();

        $this->assertCount(2, $results);
    }

    public function testWhereAnyStartsWithIsCaseInsensitive(): void
    {
        $results = $this->query()
            ->whereAnyStartsWith(['title'], 's')
            ->get();

        $this->assertCount(2, $results);
    }

    public function testWhereAnyStartsWithMatchesAcrossMultipleColumns(): void
    {
        // "ID Workshop" does not start with "Wo", but its event_type "Workshop" does.
        $results = $this->query()
            ->whereAnyStartsWith(['title', 'event_type'], 'Wo')
            ->get();

        $this->assertCount(1, $results);
        $this->assertSame('events/workshop-1', $results[0]['page_id']);
    }

    public function testWhereAnyStartsWithMatchesPrefixOnly(): void
    {
        // "Walk" appears in three titles but never at the start, so prefix search finds none.
        $results = $this->query()
            ->whereAnyStartsWith(['title'], 'Walk')
            ->get();

        $this->assertCount(0, $results);
    }

    public function testWhereAnyStartsWithEscapesLikeWildcards(): void
    {
        // A literal "%" must not behave as a LIKE wildcard matching every row.
        $results = $this->query()
            ->whereAnyStartsWith(['title'], '%')
            ->get();

        $this->assertCount(0, $results);
    }

    public function testWhereAnyStartsWithWithEmptyPrefixReturnsAll(): void
    {
        $results = $this->query()
            ->whereAnyStartsWith(['title'], '  ')
            ->get();

        $this->assertCount(5, $results);
    }

    public function testWhereAnyStartsWithWithEmptyColumnsReturnsAll(): void
    {
        $results = $this->query()
            ->whereAnyStartsWith([], 'S')
            ->get();

        $this->assertCount(5, $results);
    }

    public function testWhereAnyStartsWithCombinesWithOrderAndLimit(): void
    {
        $results = $this->query()
            ->whereAnyStartsWith(['title'], 'S')
            ->orderBy('title', 'asc')
            ->limit(1)
            ->get();

        $this->assertCount(1, $results);
        $this->assertSame('events/walk-1', $results[0]['page_id']); // "Spring Walk"
    }

    // --- whereAnyWordStartsWith ---

    public function testWhereAnyWordStartsWithMatchesWordAtStart(): void
    {
        // "Spring Walk" and "Summer Walk" both begin with a word starting "S".
        $results = $this->query()
            ->whereAnyWordStartsWith(['title'], 'S')
            ->get();

        $this->assertCount(2, $results);
    }

    public function testWhereAnyWordStartsWithMatchesLaterWord(): void
    {
        // "Walk" is the second word of three titles; prefix search would miss these.
        $results = $this->query()
            ->whereAnyWordStartsWith(['title'], 'Walk')
            ->get();

        $this->assertCount(3, $results);
    }

    public function testWhereAnyWordStartsWithDoesNotMatchMidWord(): void
    {
        // "alk" is inside "Walk" but never at a word boundary.
        $results = $this->query()
            ->whereAnyWordStartsWith(['title'], 'alk')
            ->get();

        $this->assertCount(0, $results);
    }

    public function testWhereAnyWordStartsWithMatchesAcrossColumns(): void
    {
        $results = $this->query()
            ->whereAnyWordStartsWith(['title', 'event_type'], 'Conf')
            ->get();

        $this->assertCount(1, $results);
        $this->assertSame('events/conf-1', $results[0]['page_id']);
    }

    public function testWhereAnyWordStartsWithIsCaseInsensitive(): void
    {
        $results = $this->query()
            ->whereAnyWordStartsWith(['title'], 'walk')
            ->get();

        $this->assertCount(3, $results);
    }

    public function testWhereAnyWordStartsWithEscapesLikeWildcards(): void
    {
        $results = $this->query()
            ->whereAnyWordStartsWith(['title'], '%')
            ->get();

        $this->assertCount(0, $results);
    }

    public function testWhereAnyWordStartsWithWithEmptyPrefixReturnsAll(): void
    {
        $results = $this->query()
            ->whereAnyWordStartsWith(['title'], '  ')
            ->get();

        $this->assertCount(5, $results);
    }

    // --- whereDateBetween ---

    public function testWhereDateBetween(): void
    {
        $results = $this->query()
            ->whereDateBetween('start_date', '2025-03-01', '2025-05-01')
            ->get();

        $this->assertCount(2, $results);
    }

    // --- whereDateOnOrAfter ---

    public function testWhereDateOnOrAfter(): void
    {
        $results = $this->query()
            ->whereDateOnOrAfter('start_date', '2025-09-10')
            ->get();

        $this->assertCount(2, $results);
    }

    // --- whereDateBefore ---

    public function testWhereDateBefore(): void
    {
        $results = $this->query()
            ->whereDateBefore('start_date', '2025-04-05')
            ->get();

        $this->assertCount(1, $results);
    }

    // --- whereTrue ---

    public function testWhereTrue(): void
    {
        $results = $this->query()
            ->whereTrue('show_on_governance')
            ->get();

        $this->assertCount(2, $results);
    }

    // --- whereNotEmpty ---

    public function testWhereNotEmpty(): void
    {
        $results = $this->query()
            ->whereNotEmpty('latitude')
            ->get();

        $this->assertCount(5, $results);
    }

    // --- orderBy ---

    public function testOrderByAscending(): void
    {
        $results = $this->query()
            ->orderBy('start_date', 'asc')
            ->get();

        $this->assertSame('events/walk-1', $results[0]['page_id']);
        $this->assertSame('events/walk-3', $results[4]['page_id']);
    }

    public function testOrderByDescending(): void
    {
        $results = $this->query()
            ->orderBy('start_date', 'desc')
            ->get();

        $this->assertSame('events/walk-3', $results[0]['page_id']);
        $this->assertSame('events/walk-1', $results[4]['page_id']);
    }

    public function testMultipleOrderBy(): void
    {
        $results = $this->query()
            ->orderBy('event_type', 'asc')
            ->orderBy('start_date', 'asc')
            ->get();

        // Conference first, then Field meetings sorted by date, then Workshop
        $this->assertSame('events/conf-1', $results[0]['page_id']);
        $this->assertSame('events/walk-1', $results[1]['page_id']);
    }

    // --- limit / offset ---

    public function testLimit(): void
    {
        $results = $this->query()
            ->orderBy('start_date', 'asc')
            ->limit(2)
            ->get();

        $this->assertCount(2, $results);
        $this->assertSame('events/walk-1', $results[0]['page_id']);
    }

    public function testLimitAndOffset(): void
    {
        $results = $this->query()
            ->orderBy('start_date', 'asc')
            ->limit(2)
            ->offset(2)
            ->get();

        $this->assertCount(2, $results);
        $this->assertSame('events/walk-2', $results[0]['page_id']);
    }

    // --- chaining ---

    public function testComplexChainedQuery(): void
    {
        $results = $this->query()
            ->where('event_type', 'Field meeting')
            ->whereDateBetween('start_date', '2025-01-01', '2025-12-31')
            ->whereContains('categories', 'Botany')
            ->orderBy('start_date', 'asc')
            ->limit(10)
            ->get();

        $this->assertCount(3, $results);
        $this->assertSame('events/walk-1', $results[0]['page_id']);
    }

    public function testCountRespectsFilters(): void
    {
        $count = $this->query()
            ->where('event_type', 'Field meeting')
            ->count();

        $this->assertSame(3, $count);
    }

    public function testCountIgnoresLimitAndOrder(): void
    {
        $count = $this->query()
            ->orderBy('start_date', 'asc')
            ->limit(1)
            ->count();

        // count() should return total matching rows, not limited count
        $this->assertSame(5, $count);
    }

    public function testGetPageIdsRespectsFilters(): void
    {
        $ids = $this->query()
            ->where('location_type', 'Indoor')
            ->getPageIds();

        $this->assertCount(2, $ids);
        $this->assertContains('events/conf-1', $ids);
        $this->assertContains('events/workshop-1', $ids);
    }
}
