<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\PageCreateRecovery;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PageCreateRecovery.
 *
 * The service exists for one situation: a createChild()/changeStatus() call
 * lost a filesystem race against a concurrent request creating the same slug
 * (Kirby's "The page directory cannot be moved"). Recovery must therefore see
 * the disk as it is NOW, not as the caller's cached Page object remembers it —
 * every test here primes the parent's children cache first and then changes
 * the disk behind its back, because that staleness is the entire point.
 *
 * Kirby is booted once up front against a writable temp content root so the
 * global handlers it registers stay out of the per-test window.
 */
final class PageCreateRecoveryTest extends TestCase
{
    private static App $kirby;

    private static Page $parent;

    public static function setUpBeforeClass(): void
    {
        // Unique namespace per run: boot() re-uses its temp dir per name, and
        // these tests write real page directories that must not accumulate.
        self::$kirby = \BSBI\WebBase\Testing\KirbyTestEnvironment::boot('kirby-base-page-recovery-' . uniqid());

        self::$parent = self::$kirby->impersonate(
            'kirby',
            fn (): Page => self::$kirby->site()
                ->createChild(['slug' => 'orders', 'template' => 'default', 'content' => ['title' => 'Orders']])
                ->changeStatus('listed')
        );
    }

    public function testFindsPublishedPageTheCallersCacheCannotSee(): void
    {
        // Prime the cache, then fabricate a listed sibling directly on disk —
        // exactly what a winning concurrent request leaves behind.
        $this->assertCount(0, self::$parent->children());
        $this->fabricateListedChild('race-published', ['Title' => 'Winner']);

        $recovered = $this->recovery()->recover(self::$parent, 'race-published', true);

        $this->assertInstanceOf(Page::class, $recovered);
        $this->assertSame('race-published', $recovered->slug());
        $this->assertTrue($recovered->isListed());
    }

    public function testRemovesStrayDraftWhenPublishedPageExists(): void
    {
        // The losing request's own createChild() leaves a draft behind when its
        // changeStatus() fails; recovery must clear that litter.
        $this->fabricateListedChild('race-litter', ['Title' => 'Winner']);
        $draftRoot = $this->fabricateDraft('race-litter', ['Title' => 'Loser draft']);

        $recovered = $this->recovery()->recover(self::$parent, 'race-litter', true);

        $this->assertInstanceOf(Page::class, $recovered);
        $this->assertTrue($recovered->isListed());
        $this->assertDirectoryDoesNotExist($draftRoot);
    }

    public function testRemovesUnlistedDuplicateWhenListedPageExists(): void
    {
        // Kirby lists a page draft → unlisted → numbered; a loser that died on
        // the final rename leaves an unlisted bare-slug duplicate of the
        // winner's listed directory. Recovery must remove it — Kirby keys
        // collections by page id, so the duplicate cannot even be represented.
        $this->fabricateListedChild('race-unlisted', ['Title' => 'Winner']);
        $bareRoot = self::$parent->root() . '/race-unlisted';
        $this->writePageDir($bareRoot, ['Title' => 'Loser, half-listed']);

        $recovered = $this->recovery()->recover(self::$parent, 'race-unlisted', true);

        $this->assertInstanceOf(Page::class, $recovered);
        $this->assertTrue($recovered->isListed());
        $this->assertDirectoryDoesNotExist($bareRoot);
    }

    public function testListsARecoveredDraftWhenAListedPageWasWanted(): void
    {
        // The winner created the draft but had not yet listed it — recovery
        // finishes the job the caller originally asked createPage() for.
        $this->fabricateDraft('race-draft', ['Title' => 'Half done']);

        $recovered = $this->recovery()->recover(self::$parent, 'race-draft', true);

        $this->assertInstanceOf(Page::class, $recovered);
        $this->assertTrue($recovered->isListed());
    }

    public function testLeavesARecoveredDraftUnlistedWhenNoListingWasWanted(): void
    {
        $this->fabricateDraft('race-draft-stays', ['Title' => 'Draft page']);

        $recovered = $this->recovery()->recover(self::$parent, 'race-draft-stays', false);

        $this->assertInstanceOf(Page::class, $recovered);
        $this->assertTrue($recovered->isDraft());
    }

    public function testReturnsNullWhenNothingExistsForTheSlug(): void
    {
        $this->assertNull($this->recovery()->recover(self::$parent, 'never-created', true));
    }

    private function recovery(): PageCreateRecovery
    {
        return new PageCreateRecovery(self::$kirby);
    }

    /**
     * Writes a listed child page directly to disk, bypassing Kirby, so the
     * parent's cached children collection stays stale.
     *
     * @param string $slug The child slug
     * @param array<string, string> $fields Content fields (keys as stored in the txt file)
     */
    private function fabricateListedChild(string $slug, array $fields): void
    {
        $num = self::$parent->children()->listed()->count() + 1;
        $this->writePageDir(self::$parent->root() . '/' . $num . '_' . $slug, $fields);
    }

    /**
     * Writes a draft page directly to disk, bypassing Kirby.
     *
     * @param string $slug The draft slug
     * @param array<string, string> $fields Content fields (keys as stored in the txt file)
     * @return string The draft directory written
     */
    private function fabricateDraft(string $slug, array $fields): string
    {
        $root = self::$parent->root() . '/_drafts/' . $slug;
        $this->writePageDir($root, $fields);
        return $root;
    }

    /**
     * @param string $root Absolute page directory to create
     * @param array<string, string> $fields Content fields to write
     */
    private function writePageDir(string $root, array $fields): void
    {
        mkdir($root, 0777, true);
        $lines = [];
        foreach ($fields as $key => $value) {
            $lines[] = $key . ': ' . $value;
        }
        file_put_contents($root . '/default.txt', implode("\n\n----\n\n", $lines));
    }
}
