<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Filesystem\Dir;
use Throwable;

/**
 * Recovers from a lost page-creation race.
 *
 * When two concurrent requests create the same child slug, the loser fails
 * inside Kirby's changeStatus()/Dir::move ("The page directory cannot be
 * moved"): either the winner already occupies the target directory, or the
 * shared draft directory was already moved away. By then the page the caller
 * wanted exists on disk — it just is not the object the caller made, and the
 * caller's cached Page collections cannot see it.
 *
 * This service re-reads the parent fresh from disk and hands back the page
 * that won, finishing or tidying whatever the lost race left behind.
 */
final readonly class PageCreateRecovery
{
    /**
     * @param App $kirby The Kirby application (used to impersonate for page actions)
     */
    public function __construct(private App $kirby)
    {
    }

    /**
     * Attempts to recover the page a failed create call raced against.
     *
     * @param Page $parentPage The parent the child was being created under
     * @param string $slug The slug the failed create was writing
     * @param bool $expectListed Whether the caller wanted the page listed
     * @return Page|null The recovered page, or null when nothing exists for the
     *                   slug (the failure was not a race — rethrow the original)
     * @throws Throwable If listing a recovered draft fails
     */
    public function recover(Page $parentPage, string $slug, bool $expectListed): ?Page
    {
        $this->removeRaceLitter($parentPage, $slug);

        // Clone the parent to drop its cached inventory: the whole reason the
        // create failed is that the cached view predates the winning request.
        $freshParent = $parentPage->clone();

        $published = $freshParent->children()->findBy('slug', $slug);
        if ($published instanceof Page) {
            return $published;
        }

        $draft = $freshParent->drafts()->findBy('slug', $slug);
        if ($draft instanceof Page) {
            if ($expectListed) {
                $listed = $this->kirby->impersonate(
                    'kirby',
                    fn (): Page => $draft->changeStatus('listed')
                );
                return $listed instanceof Page ? $listed : $draft;
            }
            return $draft;
        }

        return null;
    }

    /**
     * Removes what the lost race left beside the winner's listed directory.
     *
     * Kirby lists a page in two moves — draft → unlisted (bare slug directory),
     * then unlisted → numbered directory — and the loser dies on whichever move
     * collides. So when a numbered directory exists for the slug, a bare-slug
     * sibling or a leftover draft is litter from the lost race: same slug, same
     * page id, duplicate directory. It is removed at the filesystem level
     * because Kirby's collections key pages by id and so cannot even represent
     * (let alone delete) the duplicate. Best-effort: recovery is still valid if
     * a removal fails.
     *
     * @param Page $parentPage The parent whose children raced
     * @param string $slug The raced slug
     */
    private function removeRaceLitter(Page $parentPage, string $slug): void
    {
        $parentRoot = $parentPage->root();

        // The glob is deliberately broad; the regex then enforces exactly
        // "<digits>_<slug>", so nothing but a numbered directory for this very
        // slug can qualify (and pathological slugs can never produce a match).
        $numbered = array_filter(
            glob($parentRoot . '/*_' . $slug) ?: [],
            fn (string $dir): bool => preg_match('/^\d+_' . preg_quote($slug, '/') . '$/', basename($dir)) === 1
        );
        if ($numbered === []) {
            return;
        }

        foreach ([$parentRoot . '/' . $slug, $parentRoot . '/_drafts/' . $slug] as $litter) {
            if (is_dir($litter)) {
                try {
                    Dir::remove($litter);
                } catch (Throwable) {
                    // The listed page is still the right answer; leave the litter.
                }
            }
        }
    }
}
