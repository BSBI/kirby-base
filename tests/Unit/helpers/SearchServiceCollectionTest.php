<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\SearchService;
use BSBI\WebBase\Testing\KirbyContentBuilder;
use BSBI\WebBase\Testing\KirbyTestEnvironment;
use Kirby\Cms\App;
use Kirby\Cms\Pages;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SearchService::getSearchCollection() paging (bsbi-web#703).
 *
 * The in-memory search is used two ways: the site search asks for a page of
 * results ($perPage > 0), while filtered listings (news, blog, press releases,
 * referees) ask for the whole match set with $perPage = 0 and paginate it
 * themselves. Kirby's Pagination::for() drops a zero limit as a falsy value
 * and falls back to 20 per page, so the "whole set" was silently a 20-item
 * slice of the current URL page — page 2 of a listing then re-paginated the
 * search's own page 2, which for 11–30 matches does not exist and 404s.
 *
 * One App boots in setUpBeforeClass (constructing an App registers global
 * handlers, which PHPUnit flags as risky inside a test).
 */
final class SearchServiceCollectionTest extends TestCase
{
    private static App $kirby;

    public static function setUpBeforeClass(): void
    {
        self::$kirby = KirbyTestEnvironment::boot('kirby-base-search-collection-' . uniqid());
    }

    public function testZeroPerPageReturnsEveryMatchUnpaginated(): void
    {
        $results = $this->service()->getSearchCollection('grassland', 'title|mainContent', 0, $this->stories(25));

        $this->assertSame(25, $results->count());
        $this->assertNull($results->pagination());
    }

    public function testZeroPerPageStillExcludesNonMatches(): void
    {
        $results = $this->service()->getSearchCollection('grassland', 'title|mainContent', 0, $this->stories(25));

        $this->assertFalse($results->has('filler-1'));
        $this->assertSame(0, $results->filter(fn ($p) => str_starts_with($p->slug(), 'filler-'))->count());
    }

    public function testZeroPerPageKeepsScoreOrdering(): void
    {
        $results = $this->service()->getSearchCollection('grassland', 'title|mainContent', 0, $this->stories(5));

        // Title hits outweigh body hits, so the title-matching stories lead.
        $this->assertSame('grassland-title-1', $results->first()?->slug());
        $this->assertSame('grassland-body-4', $results->last()?->slug());
    }

    public function testPositivePerPageReturnsOnePageWithFullTotal(): void
    {
        $results = $this->service()->getSearchCollection('grassland', 'title|mainContent', 10, $this->stories(25));

        $this->assertSame(10, $results->count());
        $this->assertNotNull($results->pagination());
        $this->assertSame(25, $results->pagination()->total());
        $this->assertSame(3, $results->pagination()->pages());
    }

    public function testEmptyQueryReturnsNothing(): void
    {
        $results = $this->service()->getSearchCollection('  ', 'title|mainContent', 0, $this->stories(5));

        $this->assertSame(0, $results->count());
    }

    private function service(): SearchService
    {
        return new SearchService(self::$kirby->site(), self::$kirby);
    }

    /**
     * $matching stories that mention grassland (half in the title, half in the
     * body), plus five filler stories that never match, so a count proves the
     * filter as well as the slice.
     *
     * @return Pages<\Kirby\Cms\Page>
     */
    private function stories(int $matching): Pages
    {
        $builder = new KirbyContentBuilder();
        $pages = [];
        for ($i = 1; $i <= $matching; $i++) {
            $pages[] = $i % 2 === 1
                ? $builder->page(['title' => "Grassland survey $i", 'mainContent' => 'A day in the field.'], "grassland-title-$i")
                : $builder->page(['title' => "Field meeting $i", 'mainContent' => 'We walked the grassland.'], "grassland-body-$i");
        }
        for ($i = 1; $i <= 5; $i++) {
            $pages[] = $builder->page(['title' => "Woodland walk $i", 'mainContent' => 'Trees and moss.'], "filler-$i");
        }
        return new Pages($pages);
    }
}
