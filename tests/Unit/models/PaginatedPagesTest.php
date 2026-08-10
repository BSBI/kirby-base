<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\models;

use BSBI\WebBase\models\PaginatedPages;
use BSBI\WebBase\Testing\KirbyContentBuilder;
use BSBI\WebBase\Testing\KirbyTestEnvironment;
use Kirby\Cms\Pages;
use Kirby\Cms\Pagination;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PaginatedPages — a page of results that already knows it is one page of a
 * larger set.
 *
 * Kirby's own Collection::paginate() derives the total from the collection it is given
 * and then slices it, which requires holding every result in memory. Search cannot
 * afford that, so it fetches one page of IDs and states the total separately. These
 * tests pin the thing that makes it safe: attaching a pagination must not slice.
 */
final class PaginatedPagesTest extends TestCase
{
    private static KirbyContentBuilder $content;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        KirbyTestEnvironment::boot('paginated-pages-tests');
        self::$content = new KirbyContentBuilder();
    }

    /**
     * @param int $count how many pages to build
     * @return Pages
     */
    private function pages(int $count): Pages
    {
        $pages = new Pages([]);
        for ($i = 1; $i <= $count; $i++) {
            $pages->add(self::$content->page(['title' => 'Result ' . $i], 'result-' . $i));
        }

        return $pages;
    }

    /**
     * The collection must survive intact. Kirby's paginate() would slice it to the
     * offset the pagination describes — on page 2 of a 10-item slice that is offset 10
     * into a 10-item collection, i.e. nothing at all.
     */
    public function testKeepsEveryItemItWasGiven(): void
    {
        $pagination = new Pagination(['page' => 2, 'limit' => 10, 'total' => 500]);

        $result = PaginatedPages::from($this->pages(10), $pagination);

        $this->assertCount(10, $result);
    }

    public function testCarriesTheStatedTotalRatherThanItsOwnCount(): void
    {
        $pagination = new Pagination(['page' => 1, 'limit' => 10, 'total' => 500]);

        $result = PaginatedPages::from($this->pages(10), $pagination);

        $this->assertNotNull($result->pagination());
        $this->assertSame(500, $result->pagination()->total());
        $this->assertSame(50, $result->pagination()->pages());
    }

    public function testKeepsTheCurrentPageNumber(): void
    {
        $pagination = new Pagination(['page' => 3, 'limit' => 10, 'total' => 500]);

        $result = PaginatedPages::from($this->pages(10), $pagination);

        $this->assertNotNull($result->pagination());
        $this->assertSame(3, $result->pagination()->page());
    }

    public function testPreservesTheOrderItWasGiven(): void
    {
        $pagination = new Pagination(['page' => 1, 'limit' => 10, 'total' => 3]);

        $result = PaginatedPages::from($this->pages(3), $pagination);

        $this->assertSame(
            ['result-1', 'result-2', 'result-3'],
            array_map(fn($page) => $page->slug(), $result->values())
        );
    }

    public function testAnEmptyPageIsStillPaginated(): void
    {
        $pagination = new Pagination(['page' => 1, 'limit' => 10, 'total' => 0]);

        $result = PaginatedPages::from(new Pages([]), $pagination);

        $this->assertCount(0, $result);
        $this->assertNotNull($result->pagination());
        $this->assertSame(0, $result->pagination()->total());
    }
}
