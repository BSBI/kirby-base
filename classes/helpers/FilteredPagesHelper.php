<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

use Kirby\Cms\Page;
use Kirby\Content\Field;
use Kirby\Exception\InvalidArgumentException;

/**
 * Generic helper for the filteredpages panel section.
 *
 * Provides two groups of methods:
 *  - Pure static methods (applyFilters, applySearch, applySort, paginate) that
 *    operate on plain PHP arrays and can be unit-tested without a Kirby bootstrap.
 *  - Kirby-dependent methods (getOptions, getResults) that read live page data.
 *
 * Page data arrays have the shape:
 * <code>
 * [
 *   'id'            => string,          // e.g. 'events/summer-foray-2025'
 *   'title'         => string,
 *   'status'        => string,          // 'listed' | 'unlisted' | 'draft'
 *   'panelUrl'      => string,
 *   'filterValues'  => [                // one key per configured filter
 *     'activities' => ['activities/botany', ...],
 *     'projects'   => ['projects/atlas', ...],
 *   ],
 *   'displayValues' => [                // one key per configured column + title/status
 *     'title'     => string,
 *     'status'    => string,
 *     'startDate' => string,
 *   ],
 * ]
 * </code>
 *
 * @package BSBI\WebBase\helpers
 */
class FilteredPagesHelper
{
    // ── Pure methods (no Kirby dependency) ────────────────────────────────

    /**
     * Filters pages by the active dropdown selections.
     *
     * Each active filter value must appear in the corresponding filterValues
     * array (AND logic across filters; empty/null values are skipped).
     *
     * Supports filter types 'pages', 'siteStructure', and 'dateYear'. Each filter
     * definition may include an optional 'mode' key:
     *  - 'include' (default): only keep pages that match the selected value.
     *  - 'exclude': keep pages that do NOT match the selected value.
     *
     * @param array<int, array<string, mixed>>    $pages      Pages data arrays.
     * @param array<string, array<string, mixed>> $filterDefs Filter definitions keyed by field name.
     * @param array<string, string>               $active     Selected values keyed by field name.
     * @return array<int, array<string, mixed>>
     */
    public static function applyFilters(array $pages, array $filterDefs, array $active): array
    {
        $filtered = [];
        foreach ($pages as $page) {
            $match = true;
            foreach ($active as $field => $value) {
                if ($value === '' || $value === null) {
                    continue;
                }
                if (!isset($filterDefs[$field])) {
                    continue;
                }
                $type       = $filterDefs[$field]['type'] ?? 'pages';
                $exclude    = (($filterDefs[$field]['mode'] ?? 'include') === 'exclude');
                $pageValues = $page['filterValues'][$field] ?? [];

                if ($type === 'pages' || $type === 'siteStructure' || $type === 'dateYear') {
                    $hit = in_array($value, $pageValues, true);
                    if ($exclude ? $hit : !$hit) {
                        $match = false;
                        break;
                    }
                }
            }
            if ($match) {
                $filtered[] = $page;
            }
        }
        return $filtered;
    }

    /**
     * Filters pages to those whose title contains the search string (case-insensitive).
     *
     * An empty search string returns all pages unchanged.
     *
     * @param array<int, array<string, mixed>> $pages  Pages data arrays.
     * @param string                           $search Search string.
     * @return array<int, array<string, mixed>>
     */
    public static function applySearch(array $pages, string $search): array
    {
        if ($search === '') {
            return $pages;
        }
        $lower = strtolower($search);
        return array_values(
            array_filter($pages, fn(array $p) => str_contains(strtolower($p['title']), $lower))
        );
    }

    /**
     * Sorts pages by a display field, ascending or descending.
     *
     * Sort value is resolved from displayValues[$field], falling back to
     * the top-level $page[$field] key (covers 'id', 'title', 'status').
     * Comparison is lexicographic.
     *
     * @param array<int, array<string, mixed>> $pages     Pages data arrays.
     * @param string                           $sortField Field name to sort by.
     * @param string                           $sortDir   'asc' or 'desc'.
     * @return array<int, array<string, mixed>>
     */
    public static function applySort(array $pages, string $sortField, string $sortDir): array
    {
        usort($pages, function (array $a, array $b) use ($sortField, $sortDir): int {
            $aVal = $a['displayValues'][$sortField] ?? $a[$sortField] ?? '';
            $bVal = $b['displayValues'][$sortField] ?? $b[$sortField] ?? '';
            $cmp  = strcmp((string)$aVal, (string)$bVal);
            return $sortDir === 'desc' ? -$cmp : $cmp;
        });
        return $pages;
    }

    /**
     * Decodes a JSON-encoded query parameter, distinguishing corruption from absence.
     *
     * Returns null when the parameter is present but cannot be decoded to an
     * array, so callers can log the problem rather than silently treating it as
     * "nothing selected" — which, for the filter parameters, means listing
     * everything. An empty string is treated as absence and returns [].
     *
     * @param string $raw Raw parameter value.
     * @return array<mixed>|null Decoded array, or null if the value was malformed.
     */
    public static function decodeJsonParam(string $raw): ?array
    {
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Slices a flat array of pages into a single paginated result set.
     *
     * @param array<int, array<string, mixed>> $pages    Full (pre-filtered) pages array.
     * @param int                              $page     1-based page number.
     * @param int                              $pageSize Items per page.
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, pageSize: int, totalPages: int}
     */
    public static function paginate(array $pages, int $page, int $pageSize): array
    {
        $total      = count($pages);
        $offset     = ($page - 1) * $pageSize;
        $totalPages = $pageSize > 0 ? (int)ceil($total / $pageSize) : 0;

        return [
            'items'      => array_values(array_slice($pages, $offset, $pageSize)),
            'total'      => $total,
            'page'       => $page,
            'pageSize'   => $pageSize,
            'totalPages' => $totalPages,
        ];
    }

    // ── Kirby-dependent methods ────────────────────────────────────────────

    /**
     * Returns available options for each configured filter dropdown.
     *
     * Supports filter type 'pages': loads the named Kirby collection and
     * returns [{value: pageId, text: pageTitle}, ...].
     *
     * Supports filter type 'siteStructure': reads a site structure field and
     * returns [{value: itemValue, text: itemValue}, ...] using the configured
     * 'siteField' and 'valueField' keys.
     *
     * Supports filter type 'dateYear': scans child pages of $modelId (filtered
     * by $template when provided) and returns the distinct years found in the
     * configured 'field', sorted descending (most recent first).
     *
     * @param array<string, array<string, mixed>> $filterDefs Filter definitions keyed by field name.
     * @param string                              $modelId    Kirby page ID of the parent page (required for dateYear).
     * @param string                              $template   Intended template to filter children by (optional).
     * @return array<string, array<int, array{value: string, text: string}>>
     */
    public static function getOptions(array $filterDefs, string $modelId = '', string $template = ''): array
    {
        $options = [];
        foreach ($filterDefs as $field => $def) {
            $type = $def['type'] ?? 'pages';
            if ($type === 'pages' && isset($def['collection'])) {
                $collection = kirby()->collection((string)$def['collection']);
                if ($collection !== null) {
                    $items = [];
                    foreach ($collection as $page) {
                        $items[] = ['value' => $page->id(), 'text' => $page->title()->value()];
                    }
                    $options[$field] = $items;
                }
            }
            if ($type === 'siteStructure' && isset($def['siteField'], $def['valueField'])) {
                try {
                    /** @var Field $siteField */
                    $siteField = kirby()->site()->content()->get((string)$def['siteField']);
                    /** @noinspection PhpUndefinedMethodInspection */
                    $structure       = $siteField->toStructure();
                    $items           = [];
                    foreach ($structure as $item) {
                        $val     = $item->content()->get((string)$def['valueField'])->value() ?? '';
                        $items[] = ['value' => $val, 'text' => $val];
                    }
                    $options[$field] = $items;
                } catch (InvalidArgumentException) {
                    $options[$field] = [];
                }
            }
            if ($type === 'dateYear' && $modelId !== '') {
                $parentPage = kirby()->page($modelId);
                if ($parentPage !== null) {
                    $children = $parentPage->children();
                    if ($template !== '') {
                        $children = $children->filterBy('intendedTemplate', $template);
                    }
                    $fieldKey = (string)($def['field'] ?? $field);
                    $years    = [];
                    foreach ($children as $child) {
                        try {
                            $raw = $child->content()->get($fieldKey)->value() ?? '';
                            if ($raw !== '') {
                                $timestamp = strtotime($raw);
                                if ($timestamp !== false) {
                                    $years[date('Y', $timestamp)] = true;
                                }
                            }
                        } catch (InvalidArgumentException) {
                            // skip pages with unparseable date fields
                        }
                    }
                    krsort($years);
                    $items = [];
                    foreach (array_keys($years) as $year) {
                        $items[] = ['value' => (string)$year, 'text' => (string)$year];
                    }
                    $options[$field] = $items;
                }
            }
        }
        return $options;
    }

    /**
     * Returns a paginated, filtered, and sorted list of child pages.
     *
     * Loads all children of $modelId matching $template, converts them to
     * plain data arrays, then applies filters, search, sort, and pagination
     * using the pure helper methods above.
     *
     * @param string                              $modelId    Kirby page ID of the parent page.
     * @param string                              $template   Intended template to filter children by.
     * @param array<string, array<string, mixed>> $filterDefs Filter definitions keyed by field name.
     * @param array<int, array<string, mixed>>    $columnDefs Column definitions (each has 'field', 'label', 'width').
     * @param array<string, string>               $active     Active filter values keyed by field name.
     * @param string                              $search     Freetext search string.
     * @param string                              $sortField  Field name to sort by.
     * @param string                              $sortDir    'asc' or 'desc'.
     * @param int                                 $page       1-based page number.
     * @param int                                 $pageSize   Items per page.
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, pageSize: int, totalPages: int}
     */
    public static function getResults(
        string $modelId,
        string $template,
        array $filterDefs,
        array $columnDefs,
        array $active,
        string $search,
        string $sortField,
        string $sortDir,
        int $page,
        int $pageSize
    ): array {
        $empty = ['items' => [], 'total' => 0, 'page' => 1, 'pageSize' => $pageSize, 'totalPages' => 0];

        $parentPage = kirby()->page($modelId);
        if ($parentPage === null) {
            KirbyBaseHelper::writeToLogFile('errors', 'FilteredPagesHelper: parent page not found: ' . $modelId);
            return $empty;
        }

        $children = $parentPage->childrenAndDrafts()->filterBy('intendedTemplate', $template);

        $pages = [];
        foreach ($children as $child) {
            $pages[] = self::kirbyPageToData($child, $filterDefs, $columnDefs);
        }

        $pages = self::applyFilters($pages, $filterDefs, $active);
        $pages = self::applySearch($pages, $search);
        $pages = self::applySort($pages, $sortField, $sortDir);

        return self::paginate($pages, $page, $pageSize);
    }

    /**
     * Converts a single Kirby Page into a plain data array.
     *
     * filterValues: for each 'pages'-type filter field, resolves linked pages
     * and collects their IDs.
     * displayValues: collects field values needed for column display and sorting.
     *
     * @param Page                                $page       Kirby page object.
     * @param array<string, array<string, mixed>> $filterDefs Filter definitions keyed by field name.
     * @param array<int, array<string, mixed>>    $columnDefs Column definitions.
     * @return array<string, mixed>
     */
    private static function kirbyPageToData(Page $page, array $filterDefs, array $columnDefs): array
    {
        $filterValues = [];
        foreach ($filterDefs as $fieldName => $def) {
            $type = $def['type'] ?? 'pages';
            if ($type === 'pages') {
                try {
                    /** @var Field $fieldValue */
                    $fieldValue               = $page->content()->get($fieldName);
                    /** @noinspection PhpUndefinedMethodInspection */
                    $linkedPages              = $fieldValue->toPages();
                    $filterValues[$fieldName] = $linkedPages !== null ? $linkedPages->pluck('id') : [];
                } catch (InvalidArgumentException) {
                    $filterValues[$fieldName] = [];
                }
            }
            if ($type === 'siteStructure') {
                try {
                    $raw                      = $page->content()->get($fieldName)->value() ?? '';
                    $filterValues[$fieldName] = $raw !== '' ? array_map('trim', explode(',', $raw)) : [];
                } catch (InvalidArgumentException) {
                    $filterValues[$fieldName] = [];
                }
            }
            if ($type === 'dateYear') {
                $fieldKey = (string)($def['field'] ?? $fieldName);
                try {
                    $raw       = $page->content()->get($fieldKey)->value() ?? '';
                    $timestamp = $raw !== '' ? strtotime($raw) : false;
                    $filterValues[$fieldName] = $timestamp !== false ? [date('Y', $timestamp)] : [];
                } catch (InvalidArgumentException) {
                    $filterValues[$fieldName] = [];
                }
            }
        }

        $displayValues = [
            'title'  => $page->title()->value(),
            'status' => $page->status(),
        ];
        foreach ($columnDefs as $col) {
            $colField = $col['field'] ?? '';
            if ($colField !== '' && !isset($displayValues[$colField])) {
                try {
                    /** @var Field $fieldValue */
                    $fieldValue               = $page->content()->get($colField);
                    $displayValues[$colField] = $fieldValue->value() ?? '';
                } catch (InvalidArgumentException) {
                    $displayValues[$colField] = '';
                }
            }
        }

        return [
            'id'            => $page->id(),
            'title'         => $page->title()->value(),
            'status'        => $page->status(),
            'panelUrl'      => $page->panel()->url(),
            'filterValues'  => $filterValues,
            'displayValues' => $displayValues,
        ];
    }
}
