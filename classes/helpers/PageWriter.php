<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

use Kirby\Cms\App;
use Kirby\Cms\Page;
use Throwable;

/**
 * Writes to pages — update, publish, unpublish, delete — on the object Kirby
 * holds now.
 *
 * Kirby 5's content storage is immutable: every write returns a new Page and
 * the object it replaced refuses any further write with "Storage for the page
 * is immutable and cannot be updated". A caller that holds a Page across a
 * write it did not make itself (a service that recorded something on the page,
 * a hook, an earlier helper call whose return was discarded) is holding the
 * replaced object. Every write here therefore starts from the page as Kirby
 * currently holds it, looked up by id, so callers need only go through here.
 *
 * Writes run as the system user unless asked otherwise: a site's audit hooks
 * (updatedBy, publishedBy) stamp the acting user, and some writes must record
 * the person who made them rather than "kirby".
 */
final readonly class PageWriter
{
    public function __construct(private App $kirby)
    {
    }

    /**
     * The page as Kirby holds it now, drafts included; the page given when
     * Kirby no longer has it (deleted, or not yet in any collection) or holds
     * it at a directory that no longer exists.
     */
    public function current(Page $page): Page
    {
        $held = $this->kirby->page($page->id());

        // Kirby's collections can outlive the directory behind a page: after
        // a lost create race, PageCreateRecovery removes the loser's litter
        // from disk but Kirby still holds the loser's page. Writing to that
        // ghost would recreate its directory beside the winner's, so only
        // prefer Kirby's copy while its directory still exists.
        if ($held === null || !is_dir($held->root())) {
            return $page;
        }

        return $held;
    }

    /**
     * @param array<string, mixed> $pageData
     * @param bool $asCurrentUser Write as the logged-in user rather than the system user, so
     *                            an audit hook records them; the caller is responsible for
     *                            that user having permission
     * @throws KirbyRetrievalException
     */
    public function update(Page $page, array $pageData, bool $asCurrentUser = false): Page
    {
        return $this->write($page, static fn (Page $current): Page => $current->update($pageData), $asCurrentUser);
    }

    /** @throws KirbyRetrievalException */
    public function publish(Page $page): Page
    {
        return $this->write($page, static fn (Page $current): Page => $current->changeStatus('listed'));
    }

    /** @throws KirbyRetrievalException */
    public function unpublish(Page $page): Page
    {
        return $this->write($page, static fn (Page $current): Page => $current->changeStatus('draft'));
    }

    /** @throws KirbyRetrievalException */
    public function delete(Page $page): bool
    {
        try {
            $current = $this->current($page);
            return (bool) $this->kirby->impersonate('kirby', static fn (): bool => $current->delete());
        } catch (Throwable $e) {
            throw new KirbyRetrievalException($e->getMessage());
        }
    }

    /**
     * @param callable(Page): Page $write
     * @throws KirbyRetrievalException
     */
    private function write(Page $page, callable $write, bool $asCurrentUser = false): Page
    {
        try {
            $current = $this->current($page);
            if ($asCurrentUser) {
                return $write($current);
            }
            $written = $this->kirby->impersonate('kirby', static fn (): Page => $write($current));
            return $written instanceof Page ? $written : $current;
        } catch (Throwable $e) {
            throw new KirbyRetrievalException($e->getMessage());
        }
    }
}
