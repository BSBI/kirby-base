<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

use Kirby\Cms\Page;

/**
 * Page lookups for the plugin's panel API routes, which take a page id from the
 * request. Kirby's own routes check isAccessible()/isListable() for the current
 * user; these helpers give the plugin's routes the same rule (bsbi-web#830), so a
 * role that may not open or list a page can't reach it through them either.
 */
final class PanelPageAccess
{
    /**
     * The page with this id, or null when it doesn't exist or the current user
     * may not open it.
     *
     * @param string $pageId
     * @return Page|null
     */
    public static function find(string $pageId): ?Page
    {
        $page = kirby()->page($pageId);
        return $page instanceof Page && $page->isAccessible() ? $page : null;
    }

    /**
     * Whether an item from a collection may be shown to the current user: pages
     * must be listable; anything else is left to the caller.
     *
     * @param mixed $item
     * @return bool
     */
    public static function isListable(mixed $item): bool
    {
        return !$item instanceof Page || $item->isListable();
    }
}
