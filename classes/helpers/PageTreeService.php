<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

use Kirby\Cms\Page;
use Kirby\Cms\Pages;

/**
 * Service for walking the Kirby page tree.
 *
 * Pure: it touches nothing but the Kirby page API, so it needs no collaborators.
 */
final readonly class PageTreeService
{
    /**
     * Returns the descendants of a page, in the same depth-first order as Kirby's index(),
     * but without descending into the children of pages whose intended template is listed
     * in $leafTemplates. The leaf pages themselves are included; only their subtrees are
     * skipped.
     *
     * Kirby's index() instantiates every descendant, which is wasteful when the pages being
     * looked for sit above large, uninteresting subtrees — user submissions beneath a form,
     * comments beneath an article, orders beneath a product. Naming those containers as
     * leaves keeps the walk proportional to the structural part of the tree.
     *
     * Matching is on the intended template, not template(), so a page type without a
     * corresponding template file is still recognised rather than collapsing to 'default'.
     *
     * @param Page $page The page whose descendants are wanted
     * @param array<int, string> $leafTemplates Template names whose children should be skipped
     * @return Pages<Page> The descendant pages, excluding anything below a leaf template
     */
    public function indexExcludingChildrenOf(Page $page, array $leafTemplates): Pages
    {
        /** @var Pages<Page> $index */
        $index = new Pages();

        foreach ($page->children() as $child) {
            $index->add($child);
            if (in_array($child->intendedTemplate()->name(), $leafTemplates, true) === false) {
                $index->add($this->indexExcludingChildrenOf($child, $leafTemplates));
            }
        }

        return $index;
    }
}
