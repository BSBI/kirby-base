<?php

declare(strict_types=1);

namespace BSBI\WebBase\models;

use Kirby\Cms\Pages;
use Kirby\Cms\Pagination;

/**
 * A page of results that already knows it is one page of a larger set.
 *
 * Kirby's `Collection::paginate()` derives the total from the collection it is handed
 * and then slices it, so producing page 5 of a result set means holding all of it in
 * memory first. For a search across a large site that is the difference between ten
 * page objects and several thousand — enough to exhaust the request.
 *
 * This lets a caller that already knows the total (from a `COUNT`, say) fetch only the
 * slice it needs and attach the pagination afterwards, without the slicing step that
 * would throw the slice away.
 *
 * @package BSBI\WebBase\models
 */
final class PaginatedPages extends Pages
{
    /**
     * Wrap an already-sliced collection together with the pagination describing the
     * full result set.
     *
     * @param Pages $pages The items for the current page, in the order to display them
     * @param Pagination $pagination Pagination describing the whole result set.
     *        Kirby\Cms\Pagination specifically: Cms\Collection::pagination() narrows the
     *        return type to it, and it is the variant that knows how to build page URLs.
     * @return static
     */
    public static function from(Pages $pages, Pagination $pagination): static
    {
        $collection = new static($pages->values());
        $collection->pagination = $pagination;

        return $collection;
    }
}
