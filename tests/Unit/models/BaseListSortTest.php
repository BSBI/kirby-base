<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\models;

use BSBI\WebBase\models\BaseFilter;
use BSBI\WebBase\models\BaseList;
use BSBI\WebBase\models\BaseModel;
use PHPUnit\Framework\TestCase;

/**
 * Minimal concrete BaseList subclass for testing sort properties.
 */
class StubList extends BaseList
{
    public function getListItems(): array
    {
        return $this->list;
    }

    public function getItemType(): string
    {
        return BaseModel::class;
    }

    public function getFilterType(): string
    {
        return BaseFilter::class;
    }

    public function addListItem(BaseModel $item): static
    {
        $this->add($item);
        return $this;
    }
}

/**
 * Unit tests for the sort-related properties added to BaseList.
 */
final class BaseListSortTest extends TestCase
{
    private StubList $list;

    protected function setUp(): void
    {
        $this->list = new StubList();
    }

    public function testSortByDefaultsToEmptyString(): void
    {
        $this->assertSame('', $this->list->getSortBy());
    }

    public function testSortDirectionDefaultsToAsc(): void
    {
        $this->assertSame('asc', $this->list->getSortDirection());
    }

    public function testSortableColumnsDefaultsToEmpty(): void
    {
        $this->assertSame([], $this->list->getSortableColumns());
    }

    public function testIsSortableReturnsFalseWhenNoColumnsSet(): void
    {
        $this->assertFalse($this->list->isSortable());
    }

    public function testSetSortByStoresValue(): void
    {
        $this->list->setSortBy('date');
        $this->assertSame('date', $this->list->getSortBy());
    }

    public function testSetSortDirectionStoresValue(): void
    {
        $this->list->setSortDirection('desc');
        $this->assertSame('desc', $this->list->getSortDirection());
    }

    public function testSetSortableColumnsStoresValues(): void
    {
        $columns = ['title', 'author', 'date'];
        $this->list->setSortableColumns($columns);
        $this->assertSame($columns, $this->list->getSortableColumns());
    }

    public function testIsSortableReturnsTrueWhenColumnsSet(): void
    {
        $this->list->setSortableColumns(['title', 'date']);
        $this->assertTrue($this->list->isSortable());
    }

    public function testSettersReturnFluentInterface(): void
    {
        $result = $this->list
            ->setSortBy('author')
            ->setSortDirection('asc')
            ->setSortableColumns(['author']);

        $this->assertSame($this->list, $result);
    }

    public function testAriaSortIsEmptyForNonSortableColumn(): void
    {
        $this->list->setSortableColumns(['title']);
        $this->assertSame('', $this->list->getAriaSortForColumn('author'));
    }

    public function testAriaSortIsNoneForSortableButInactiveColumn(): void
    {
        $this->list->setSortableColumns(['title', 'date'])
            ->setSortBy('date')
            ->setSortDirection('desc');
        $this->assertSame('none', $this->list->getAriaSortForColumn('title'));
    }

    public function testAriaSortIsAscendingForActiveAscendingColumn(): void
    {
        $this->list->setSortableColumns(['title'])
            ->setSortBy('title')
            ->setSortDirection('asc');
        $this->assertSame('ascending', $this->list->getAriaSortForColumn('title'));
    }

    public function testAriaSortIsDescendingForActiveDescendingColumn(): void
    {
        $this->list->setSortableColumns(['date'])
            ->setSortBy('date')
            ->setSortDirection('desc');
        $this->assertSame('descending', $this->list->getAriaSortForColumn('date'));
    }
}
