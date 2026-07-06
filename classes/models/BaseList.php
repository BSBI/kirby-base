<?php

namespace BSBI\WebBase\models;

use BSBI\WebBase\traits\ErrorHandling;

/**
 * Base abstract class for managing lists of model objects.
 *
 * This class provides common functionality for storing, filtering, and paginating
 * collections of model objects. It uses a generic type parameter T which should
 * extend BaseModel to ensure type safety across implementations.
 *
 * Features:
 * - Generic type support for list items
 * - Filtering capabilities through BaseFilter
 * - Optional pagination support
 * - Basic list operations (add, get, count)
 * - Item search functionality
 *
 * @template T of BaseModel
 * @template TFilter of BaseFilter
 */


abstract class BaseList
{
    use ErrorHandling;

    /**
     * @return T[]
     * @noinspection PhpUnused
     */
    public function getListItems(): array
    {
        return $this->list;
    }

    /**
     * @return string
     * @noinspection PhpUnused
     */
    abstract function getItemType(): string;

    /**
     * @return string
     * @noinspection PhpUnused
     */
    abstract function getFilterType(): string;

    /** @var T[] $list */
    protected array $list = [];

    /** @var TFilter $filter */
    protected BaseFilter $filter;

    /**
     * @var Pagination
     */
    private Pagination $pagination;

    private int $paginatePerPage = 10;


    /**
     * Add the item.
     * For implementations, use a specific add function, e.g. addCategory, that enforces type
     * @param T $item
     * @return $this
     * @noinspection PhpDocSignatureInspection
     */
    protected function add(BaseModel $item): BaseList
    {
        $this->list[] = $item;
        return $this;
    }

    /**
     * List the items
     * @return T[]
     * @noinspection PhpUnused
     */
    public function getList(): array
    {
        return $this->list;
    }


    /**
     * @return bool
     */
    public function hasListItems(): bool {
        return count($this->list) > 0;
    }

    /**
     * @param int $x
     * @return array
     */
    public function getFirstXItems(int $x): array {
        return array_slice($this->list, 0, $x);
    }

    /**
     * @param int $index
     * @return array
     */
    public function getItemsFromIndex(int $index): array {
        return array_slice($this->list, $index-1);
    }

    /**
     * count categories
     * @return int the number of categories
     */
    public function count(): int
    {
        return count($this->list);
    }

    /**
     * Find the item with a matching name
     * Will return null if no item found
     * @param string $title
     * @return BaseModel|null
     * @noinspection PhpUnused
     */
    protected function findItemByTitle(string $title): BaseModel|null
    {
        // Function to find the matching object
        $matchingItem = array_filter($this->list, function ($item) use ($title) {
            return $item->getTitle() === $title;
        });

        // Get the first matching result, if any
        $matchingItem = reset($matchingItem);

        if ($matchingItem) {
            return $matchingItem;
        } else {
            return null;
        }
    }


    /**
     * Returns the category string for a given list item.
     * Override in subclasses to enable groupByCategory() support.
     *
     * @param BaseModel $item
     * @return string
     * @noinspection PhpUnusedParameterInspection
     */
    protected function getCategoryForItem(BaseModel $item): string
    {
        return '';
    }

    /**
     * Returns the fixed display order for categories.
     * Override in subclasses to enforce a specific order.
     * An empty array means categories are sorted alphabetically.
     *
     * @return array<int, string>
     */
    protected function getCategoryOrder(): array
    {
        return [];
    }

    /**
     * Groups list items by category, using getCategoryForItem() and getCategoryOrder().
     * Items with no category (empty string) are placed last under $uncategorisedLabel.
     * If getCategoryOrder() is non-empty, categories follow that order; otherwise alphabetical.
     *
     * @param string $uncategorisedLabel Key used for items with no category
     * @return array<string, BaseModel[]>
     */
    public function groupByCategory(string $uncategorisedLabel = 'Other'): array
    {
        $grouped = [];
        $uncategorised = [];

        foreach ($this->list as $item) {
            $category = $this->getCategoryForItem($item);
            if ($category === '') {
                $uncategorised[] = $item;
            } else {
                $grouped[$category][] = $item;
            }
        }

        $categoryOrder = $this->getCategoryOrder();
        if (empty($categoryOrder)) {
            ksort($grouped);
        } else {
            $ordered = [];
            foreach ($categoryOrder as $category) {
                if (isset($grouped[$category])) {
                    $ordered[$category] = $grouped[$category];
                }
            }
            $remaining = array_diff_key($grouped, $ordered);
            ksort($remaining);
            $grouped = array_merge($ordered, $remaining);
        }

        if (!empty($uncategorised)) {
            $grouped[$uncategorisedLabel] = $uncategorised;
        }

        return $grouped;
    }

    /**
     * the default sort for the list (by title, intended to be overriden
     * for specific lists)
     * @return $this
     */
    public function sort(): static
    {
        return $this->sortByTitle();
    }

    /**
     * Sorts the list by the title of the items.
     *
     * @param bool $descending Optional. If true, sorts in descending order. Default is false (ascending).
     * @return $this
     * @noinspection PhpUnused
     */
    public function sortByTitle(bool $descending = false): static
    {
        return $this->sortBy(fn($item) => $item->getTitle(), true);
    }

    /**
     * @param callable(T): mixed $callback
     * @param bool $descending
     * @return $this
     */
    protected function sortBy(callable $callback, bool $descending = false): static
    {
        usort($this->list, function (BaseModel $a, BaseModel $b) use ($callback, $descending) {
            $valA = $callback($a);
            $valB = $callback($b);

            $comparison = $valA <=> $valB;
            return $descending ? -$comparison : $comparison;
        });

        return $this;
    }

    /** @var string The current sort column key */
    private string $sortBy = '';

    /** @var string The current sort direction ('asc' or 'desc') */
    private string $sortDirection = 'asc';

    /** @var string[] Allowed sort column keys for this list */
    private array $sortableColumns = [];

    /** @var string Base page URL with sort/pagination params stripped, used to build sort links */
    private string $sortBaseUrl = '';

    /**
     * @return string
     */
    public function getSortBy(): string
    {
        return $this->sortBy;
    }

    /**
     * @param string $sortBy
     * @return $this
     */
    public function setSortBy(string $sortBy): static
    {
        $this->sortBy = $sortBy;
        return $this;
    }

    /**
     * @return string
     */
    public function getSortDirection(): string
    {
        return $this->sortDirection;
    }

    /**
     * @param string $sortDirection
     * @return $this
     */
    public function setSortDirection(string $sortDirection): static
    {
        $this->sortDirection = $sortDirection;
        return $this;
    }

    /**
     * @return string[]
     */
    public function getSortableColumns(): array
    {
        return $this->sortableColumns;
    }

    /**
     * @param string[] $sortableColumns
     * @return $this
     */
    public function setSortableColumns(array $sortableColumns): static
    {
        $this->sortableColumns = $sortableColumns;
        return $this;
    }

    /**
     * Returns true if this list has any sortable columns defined.
     *
     * @return bool
     */
    public function isSortable(): bool
    {
        return !empty($this->sortableColumns);
    }

    /**
     * Returns true if the given column key is in the sortable columns list.
     *
     * @param string $columnKey
     * @return bool
     */
    public function isSortableColumn(string $columnKey): bool
    {
        return in_array($columnKey, $this->sortableColumns, true);
    }

    /**
     * Returns the effective sort direction for the given column:
     * 'asc' or 'desc' if it is the active sort column, otherwise empty string.
     *
     * @param string $columnKey
     * @return string
     */
    public function getSortDirectionForColumn(string $columnKey): string
    {
        return ($this->sortBy === $columnKey) ? $this->sortDirection : '';
    }

    /**
     * Returns the URL to sort by the given column, toggling direction if already active.
     * Requires setSortBaseUrl() to have been called first.
     *
     * @param string $columnKey
     * @return string
     */
    public function getSortUrl(string $columnKey): string
    {
        $currentDir = $this->getSortDirectionForColumn($columnKey);
        $nextDir    = ($currentDir === 'asc') ? 'desc' : 'asc';
        $separator  = str_contains($this->sortBaseUrl, '?') ? '&' : '?';
        return $this->sortBaseUrl . $separator . 'sort_by=' . urlencode($columnKey) . '&sort_dir=' . urlencode($nextDir);
    }

    /**
     * Set the base URL used for building sort links.
     * Should be the current page URL with page/sort_by/sort_dir params already stripped.
     *
     * @param string $sortBaseUrl
     * @return $this
     */
    public function setSortBaseUrl(string $sortBaseUrl): static
    {
        $this->sortBaseUrl = $sortBaseUrl;
        return $this;
    }

    /**
     * @return bool
     */
    public function usePagination(): bool {
        return false;
    }

    /**
     * @return bool
     * @noinspection PhpUnused
     */
    public function hasPagination(): bool {
        return isset($this->pagination);
    }

    /**
     * @return Pagination
     * @noinspection PhpUnused
     */
    public function getPagination(): Pagination
    {
        return $this->pagination;
    }

    /**
     * @param Pagination $pagination
     * @return $this
     */
    public function setPagination(Pagination $pagination): static
    {
        $this->pagination = $pagination;
        return $this;
    }

    /**
     * @return int
     */
    public function getPaginatePerPage(): int
    {
        return $this->paginatePerPage;
    }

    /**
     * @param int $paginatePerPage
     * @return static
     * @noinspection PhpUnused
     */
    public function setPaginatePerPage(int $paginatePerPage): static
    {
        $this->paginatePerPage = $paginatePerPage;
        return $this;
    }

    /**
     * Returns true if filters have been set on this list.
     *
     * @return bool
     */
    public function hasFilters(): bool
    {
        return isset($this->filter);
    }

    /**
     * @return TFilter
     */
    public function getFilters(): BaseFilter
    {
        return $this->filter;
    }

    /**
     * @param TFilter $filter
     * @return $this
     */
    public function setFilters(BaseFilter $filter): static
    {
        $this->filter = $filter;
        return $this;
    }


}