<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\PageCreateRecovery;
use BSBI\WebBase\helpers\PageWriter;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Exception\LogicException;
use PHPUnit\Framework\TestCase;

/**
 * Every page write goes to the object Kirby holds now.
 *
 * On capsella's staging (2026-09-21) a question sheet upload died with
 * "Storage for the page is immutable and cannot be updated": the document
 * service had written a row to the submission page, and the caller then
 * updated the object that write had replaced. Kirby 5 makes the replaced
 * object refuse writes, so the writer looks the page up afresh first.
 */
final class PageWriterTest extends TestCase
{
    private static App $kirby;

    public static ?string $lastUpdateUser = null;

    private PageWriter $writer;

    public static function setUpBeforeClass(): void
    {
        $root = sys_get_temp_dir() . '/kirby-base-page-writer-' . uniqid();
        mkdir($root . '/content', 0777, true);
        mkdir($root . '/cache', 0777, true);

        self::$kirby = new App([
            'roots' => [
                'index'   => $root,
                'content' => $root . '/content',
                'cache'   => $root . '/cache',
            ],
            'options' => ['whoops' => false],
            'users' => [['id' => 'editor', 'email' => 'editor@example.org', 'role' => 'admin']],
            'hooks' => [
                // not static: Kirby binds hooks to the App
                'page.update:after' => function (): void {
                    PageWriterTest::$lastUpdateUser = kirby()->user()?->id();
                },
            ],
        ]);
    }

    protected function setUp(): void
    {
        self::$kirby->impersonate('kirby');
        self::$lastUpdateUser = null;
        $this->writer = new PageWriter(self::$kirby);
    }

    private function aPage(string $slug): Page
    {
        return self::$kirby->site()->createChild([
            'slug'     => $slug,
            'template' => 'default',
            'content'  => ['title' => 'A page', 'state' => 'Saved'],
        ]);
    }

    /**
     * The staging failure, as Kirby produces it. Kept on its own fixture: a
     * write that fails this way leaves a stale copy of the page in Kirby's
     * collections, so nothing else may be asserted on this page afterwards.
     */
    public function testAHeldObjectRefusesAWriteAfterAnotherWrite(): void
    {
        $held = $this->aPage('held-then-refused');
        self::$kirby->page($held->id())->update(['note' => 'written by someone else']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('immutable');
        $held->update(['state' => 'Submitted']);
    }

    public function testAnUpdateAfterAnotherWriteOnTheSameObjectSucceeds(): void
    {
        $held = $this->aPage('update-after-write');
        self::$kirby->page($held->id())->update(['note' => 'written by someone else']);

        $updated = $this->writer->update($held, ['state' => 'Submitted']);

        $this->assertSame('Submitted', $updated->state()->value());
        $this->assertSame('written by someone else', $updated->note()->value(), 'the other write is kept, not overwritten');
        $this->assertSame('Submitted', self::$kirby->page($held->id())->state()->value());
    }

    public function testPublishAndUnpublishAlsoStartFromTheCurrentObject(): void
    {
        $held = $this->aPage('publish-unpublish');
        self::$kirby->page($held->id())->update(['note' => 'x']);

        $listed = $this->writer->publish($held);
        $this->assertTrue($listed->isListed());

        self::$kirby->page($held->id())->update(['note' => 'y']);
        $draft = $this->writer->unpublish($listed);
        $this->assertTrue($draft->isDraft());
    }

    public function testDeleteStartsFromTheCurrentObject(): void
    {
        $held = $this->aPage('delete-me');
        self::$kirby->page($held->id())->update(['note' => 'x']);

        $this->assertTrue($this->writer->delete($held));
        $this->assertNull(self::$kirby->page($held->id()));
    }

    public function testCurrentIsThePageItselfWhenKirbyNoLongerHasIt(): void
    {
        $page = $this->aPage('gone');
        $this->writer->delete($page);

        $this->assertSame($page, $this->writer->current($page));
    }

    /**
     * A lost create race (bsbi-web checkout, #667): the loser's publish fails
     * because the winner's directory already exists, and PageCreateRecovery
     * removes the loser's litter from disk and hands back the winner's page.
     * Kirby's collections still hold the loser's page at the removed
     * directory, so looking the page up by id finds that ghost — and writing
     * to it would recreate the directory as a duplicate of the winner's.
     */
    public function testAnUpdateAfterARecoveredCreateRaceWritesToTheWinnersPage(): void
    {
        $parent = $this->aPage('orders')->changeStatus('listed');
        // The losing request's cached view of the parent, from before the winner wrote.
        $parent->children();
        $parent->drafts();

        $winnerRoot = $parent->root() . '/1_race';
        mkdir($winnerRoot);
        file_put_contents($winnerRoot . '/default.txt', "Title: Winner\n\n----\n\nState: Saved");

        $loser = $parent->createChild(['slug' => 'race', 'template' => 'default', 'content' => ['title' => 'Loser']]);
        $collided = false;
        try {
            $loser->changeStatus('listed');
        } catch (\Throwable) {
            // the lost race, as live sees it
            $collided = true;
        }
        $this->assertTrue($collided, 'the loser\'s publish should collide with the winner\'s directory');

        $recovered = (new PageCreateRecovery(self::$kirby))->recover($parent, 'race', true);
        $this->assertInstanceOf(Page::class, $recovered);
        $this->assertSame($winnerRoot, $recovered->root());

        $updated = $this->writer->update($recovered, ['state' => 'Submitted']);

        $this->assertSame($winnerRoot, $updated->root());
        $this->assertSame([$winnerRoot], glob($parent->root() . '/*race') ?: [], 'no duplicate directory for the slug');
        $this->assertStringContainsString('Submitted', (string) file_get_contents($winnerRoot . '/default.txt'));
    }

    public function testAsCurrentUserWritesWithoutImpersonatingTheSystemUser(): void
    {
        $page = $this->aPage('audited');
        self::$kirby->impersonate('editor');

        $this->writer->update($page, ['state' => 'Submitted'], asCurrentUser: true);
        $this->assertSame('editor', self::$lastUpdateUser, 'the audit hook must see the logged-in user');

        $this->writer->update($page, ['state' => 'Saved']);
        $this->assertSame('kirby', self::$lastUpdateUser, 'the default write runs as the system user');
    }
}
