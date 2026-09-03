<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\EmailSender;
use BSBI\WebBase\helpers\ScheduledPublishService;
use DateTimeImmutable;
use DateTimeZone;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Data\Yaml;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ScheduledPublishService.
 *
 * The queue lives in the site's `scheduled` structure field; the service owns
 * every transition in and out of it. The load-bearing property (the bug that
 * prompted the extraction) is that an entry only leaves the queue when it has
 * been RESOLVED — published, or found already listed. Half-filled rows an
 * editor is still working on must survive every run.
 *
 * The clock is injected into run(), so "due" and "future" are exact. All test
 * times use the service's default Europe/London timezone.
 *
 * Two fabricated users (`editor-a`, `editor-b`) exist so `scheduledBy`
 * capture can be tested against a real impersonated session rather than the
 * 'kirby' system pseudo-user used for admin-level scaffolding (createDraft,
 * setQueue). A test App is built directly (not via KirbyTestEnvironment)
 * because it needs users defined in-memory and its own templates root.
 */
final class ScheduledPublishServiceTest extends TestCase
{
    private static App $kirby;

    /** A moment the tests treat as "now". */
    private static DateTimeImmutable $now;

    public static function setUpBeforeClass(): void
    {
        $root = sys_get_temp_dir() . '/kirby-base-scheduled-publish-' . uniqid();
        mkdir($root . '/content', 0777, true);
        mkdir($root . '/cache', 0777, true);

        self::$kirby = new App([
            'roots' => [
                'index'   => $root,
                'content' => $root . '/content',
                'cache'   => $root . '/cache',
            ],
            'users' => [
                ['id' => 'editor-a', 'email' => 'editor-a@example.org', 'role' => 'admin'],
                ['id' => 'editor-b', 'email' => 'editor-b@example.org', 'role' => 'admin'],
            ],
            'options' => [
                'defaultEmail' => 'noreply@example.org',
            ],
        ]);

        self::$now = new DateTimeImmutable('2026-09-03 12:00:00', new DateTimeZone('Europe/London'));
    }

    protected function setUp(): void
    {
        $this->setQueue([]);
        $this->setHistory([]);
    }

    public function testDueEntryPublishesPageAndLeavesQueue(): void
    {
        $page = $this->createDraft('due-page');
        $this->setQueue([$this->entry($page, '2026-09-03', '11:00:00')]);

        $result = $this->service()->run(self::$now);

        $this->assertTrue($this->refreshed($page)->isListed());
        $this->assertCount(0, $this->queueRows());
        $this->assertStringContainsString('Published 1', $result);
    }

    public function testFutureEntryStaysQueuedAndUnpublished(): void
    {
        $page = $this->createDraft('future-page');
        $this->setQueue([$this->entry($page, '2026-09-03', '13:00:00')]);

        $this->service()->run(self::$now);

        $this->assertFalse($this->refreshed($page)->isListed());
        $this->assertCount(1, $this->queueRows());
    }

    public function testEntryWithPageButNoDateStaysQueued(): void
    {
        // Regression: these half-filled rows used to be silently deleted.
        $page = $this->createDraft('no-date-yet');
        $this->setQueue([$this->entry($page, '', '')]);

        $this->service()->run(self::$now);

        $this->assertCount(1, $this->queueRows());
        $this->assertFalse($this->refreshed($page)->isListed());
    }

    public function testEntryWithDateButNoPageStaysQueued(): void
    {
        $this->setQueue([['page' => [], 'scheduledPublishDate' => '2026-09-01', 'scheduledPublishTime' => '09:00:00']]);

        $this->service()->run(self::$now);

        $this->assertCount(1, $this->queueRows());
    }

    public function testDueEntryWithEmptyTimeIsDueAtMidnight(): void
    {
        $page = $this->createDraft('midnight-page');
        $this->setQueue([$this->entry($page, '2026-09-03', '')]);

        $this->service()->run(self::$now);

        $this->assertTrue($this->refreshed($page)->isListed());
        $this->assertCount(0, $this->queueRows());
    }

    public function testAlreadyListedPageLeavesQueueWithoutBeingCounted(): void
    {
        $page = $this->createDraft('already-listed');
        self::$kirby->impersonate('kirby', fn (): Page => $page->changeStatus('listed'));
        $this->setQueue([$this->entry($page, '2026-09-03', '11:00:00')]);

        $result = $this->service()->run(self::$now);

        $this->assertCount(0, $this->queueRows());
        $this->assertStringContainsString('Published 0', $result);
    }

    public function testNothingDueDoesNotRewriteTheSiteField(): void
    {
        $page = $this->createDraft('quiet-run');
        $this->setQueue([$this->entry($page, '2026-12-25', '09:00:00')]);
        $before = $this->resetSiteFileMtime();

        $this->service()->run(self::$now);

        $this->assertSame($before, $this->siteFileMtime(), 'site.txt must not be rewritten when nothing changed');
    }

    public function testMalformedEntryIsKeptAndLaterEntriesStillProcess(): void
    {
        // A hand-mangled date must not abort the run (the old code returned
        // mid-loop on the first error, stranding everything after it).
        $broken = $this->createDraft('broken-date');
        $due = $this->createDraft('still-processed');
        $this->setQueue([
            $this->entry($broken, 'not-a-date', 'not-a-time'),
            $this->entry($due, '2026-09-03', '11:00:00'),
        ]);

        $this->service()->run(self::$now);

        $this->assertTrue($this->refreshed($due)->isListed());
        $rows = $this->queueRows();
        $this->assertCount(1, $rows);
        $this->assertFalse($this->refreshed($broken)->isListed());
    }

    public function testPublishingClearsThePagesOwnScheduleFields(): void
    {
        $page = $this->createDraft('self-scheduled', [
            'scheduledPublishDate' => '2026-09-03',
            'scheduledPublishTime' => '11:00:00',
        ]);
        $this->setQueue([$this->entry($page, '2026-09-03', '11:00:00')]);

        $this->service()->run(self::$now);

        $refreshed = $this->refreshed($page);
        $this->assertTrue($refreshed->isListed());
        $this->assertTrue($refreshed->content()->get('scheduledPublishDate')->isEmpty());
        $this->assertTrue($refreshed->content()->get('scheduledPublishTime')->isEmpty());
    }

    public function testQueueAddsAnEntryForThePage(): void
    {
        $page = $this->createDraft('queued-page');

        $this->service()->queue($page, '2026-10-01', '08:30:00');

        $rows = $this->queueRows();
        $this->assertCount(1, $rows);
        $this->assertSame('2026-10-01', $this->rowValue($rows[0], 'scheduledPublishDate'));
        $this->assertSame('08:30:00', $this->rowValue($rows[0], 'scheduledPublishTime'));
    }

    public function testQueueTwiceUpsertsToASingleEntryWithTheNewerValues(): void
    {
        $page = $this->createDraft('upserted-page');

        $this->service()->queue($page, '2026-10-01', '08:30:00');
        $this->service()->queue($page, '2026-11-05', '17:00:00');

        $rows = $this->queueRows();
        $this->assertCount(1, $rows);
        $this->assertSame('2026-11-05', $this->rowValue($rows[0], 'scheduledPublishDate'));
        $this->assertSame('17:00:00', $this->rowValue($rows[0], 'scheduledPublishTime'));
    }

    public function testQueueDefaultsAnEmptyTimeToMidnight(): void
    {
        $page = $this->createDraft('date-only-page');

        $this->service()->queue($page, '2026-10-01', '');

        $this->assertSame('00:00:00', $this->rowValue($this->queueRows()[0], 'scheduledPublishTime'));
    }

    public function testQueueWithUnchangedValuesDoesNotRewriteTheSiteField(): void
    {
        $page = $this->createDraft('unchanged-page');
        $this->service()->queue($page, '2026-10-01', '08:30:00');
        $before = $this->resetSiteFileMtime();

        $this->service()->queue($page, '2026-10-01', '08:30:00');

        $this->assertSame($before, $this->siteFileMtime(), 'an unchanged upsert must not rewrite site.txt');
    }

    public function testRemoveDeletesThePagesEntry(): void
    {
        $page = $this->createDraft('removed-page');
        $other = $this->createDraft('other-page');
        $this->service()->queue($page, '2026-10-01', '08:30:00');
        $this->service()->queue($other, '2026-10-02', '09:00:00');

        $this->service()->remove($page);

        $rows = $this->queueRows();
        $this->assertCount(1, $rows);
        $this->assertSame('2026-10-02', $this->rowValue($rows[0], 'scheduledPublishDate'));
    }

    public function testRemoveLeavesMultiPageEntriesAlone(): void
    {
        // Multi-page rows are hand-made in the site tab; per-page bookkeeping
        // must not dismantle them.
        $a = $this->createDraft('multi-a');
        $b = $this->createDraft('multi-b');
        $this->setQueue([[
            'page' => [$a->uuid()?->toString() ?? $a->id(), $b->uuid()?->toString() ?? $b->id()],
            'scheduledPublishDate' => '2026-10-01',
            'scheduledPublishTime' => '08:30:00',
        ]]);

        $this->service()->remove($a);

        $this->assertCount(1, $this->queueRows());
    }

    public function testAuthoriseIsOpenWhenNoTokenConfigured(): void
    {
        $this->assertTrue($this->service()->authorise(null));
        $this->assertTrue($this->service()->authorise('anything'));
    }

    // --- scheduledBy capture -------------------------------------------------

    public function testQueueCapturesTheCurrentUserAsScheduler(): void
    {
        $page = $this->createDraft('scheduled-by-a');

        self::$kirby->impersonate('editor-a', fn () => $this->service()->queue($page, '2026-10-01', '08:30:00'));

        $this->assertSame('editor-a', $this->rowValue($this->queueRows()[0], 'scheduledBy'));
    }

    public function testReschedulingByADifferentUserUpdatesTheScheduler(): void
    {
        $page = $this->createDraft('scheduled-by-b');
        self::$kirby->impersonate('editor-a', fn () => $this->service()->queue($page, '2026-10-01', '08:30:00'));

        self::$kirby->impersonate('editor-b', fn () => $this->service()->queue($page, '2026-11-05', '17:00:00'));

        $this->assertSame('editor-b', $this->rowValue($this->queueRows()[0], 'scheduledBy'));
    }

    public function testAnUnchangedResaveLeavesTheOriginalSchedulerAlone(): void
    {
        $page = $this->createDraft('scheduled-by-unchanged');
        self::$kirby->impersonate('editor-a', fn () => $this->service()->queue($page, '2026-10-01', '08:30:00'));

        // Same date/time, different "current user" — a routine re-save by
        // someone else must not silently reassign authorship.
        self::$kirby->impersonate('editor-b', fn () => $this->service()->queue($page, '2026-10-01', '08:30:00'));

        $this->assertSame('editor-a', $this->rowValue($this->queueRows()[0], 'scheduledBy'));
    }

    public function testManuallyAddedEntryWithNoSchedulerStillPublishes(): void
    {
        $page = $this->createDraft('no-scheduler-page');
        $this->setQueue([$this->entry($page, '2026-09-03', '11:00:00')]);

        $emailService = $this->createStub(EmailSender::class);
        $emailService->method('send')->willReturn(true);

        $result = $this->service($emailService)->run(self::$now);

        $this->assertTrue($this->refreshed($page)->isListed());
        $this->assertStringContainsString('Published 1', $result);
        $this->assertSame('', $this->rowValue($this->historyRows()[0], 'scheduledBy'));
    }

    // --- history ---------------------------------------------------------

    public function testPublishingAppendsAHistoryRowWithSchedulerTitleAndPublishedAt(): void
    {
        $page = $this->createDraft('history-page');
        $this->setQueue([$this->entry($page, '2026-09-03', '11:00:00', 'editor-a')]);

        $emailService = $this->createStub(EmailSender::class);
        $emailService->method('send')->willReturn(true);
        $this->service($emailService)->run(self::$now);

        $rows = $this->historyRows();
        $this->assertCount(1, $rows);
        $this->assertSame('editor-a', $this->rowValue($rows[0], 'scheduledBy'));
        $this->assertSame('2026-09-03 12:00:00', $this->rowValue($rows[0], 'publishedAt'));
        $this->assertSame('history-page', $this->rowValue($rows[0], 'title'));
    }

    public function testUnresolvedEntriesAddNoHistoryRow(): void
    {
        $page = $this->createDraft('future-history-page');
        $this->setQueue([$this->entry($page, '2026-12-25', '09:00:00')]);

        $this->service()->run(self::$now);

        $this->assertCount(0, $this->historyRows());
    }

    public function testHistoryOlderThan30DaysIsPrunedOnEveryRun(): void
    {
        // self::$now is 2026-09-03; 30 days back is 2026-08-04.
        $this->setHistory([
            $this->historyRow('page://old', 'Old page', 'editor-a', '2026-07-20 10:00:00'),
            $this->historyRow('page://recent', 'Recent page', 'editor-a', '2026-08-20 10:00:00'),
        ]);

        $this->service()->run(self::$now);

        $rows = $this->historyRows();
        $this->assertCount(1, $rows);
        $this->assertSame('Recent page', $this->rowValue($rows[0], 'title'));
    }

    public function testHistoryPruneAloneStillRewritesWhenTheQueueDidNotChange(): void
    {
        $this->setHistory([$this->historyRow('page://old', 'Old page', 'editor-a', '2026-07-20 10:00:00')]);

        $this->service()->run(self::$now);

        $this->assertCount(0, $this->historyRows());
    }

    public function testNothingDueAndNoStaleHistoryDoesNotRewriteTheSiteField(): void
    {
        $this->setHistory([$this->historyRow('page://recent', 'Recent page', 'editor-a', '2026-08-20 10:00:00')]);
        $before = $this->resetSiteFileMtime();

        $this->service()->run(self::$now);

        $this->assertSame($before, $this->siteFileMtime(), 'site.txt must not be rewritten when nothing changed');
    }

    // --- confirmation email ------------------------------------------------

    public function testPublishingSendsOneConfirmationEmailToTheScheduler(): void
    {
        $page = $this->createDraft('email-page');
        $this->setQueue([$this->entry($page, '2026-09-03', '11:00:00', 'editor-a')]);

        $emailService = $this->createMock(EmailSender::class);
        $emailService->expects($this->once())
            ->method('send')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                'editor-a@example.org',
                $this->anything(),
                $this->anything(),
            )
            ->willReturn(true);

        $this->service($emailService)->run(self::$now);
    }

    public function testFailedSendIsLogged(): void
    {
        $page = $this->createDraft('email-failed-send-page');
        $this->setQueue([$this->entry($page, '2026-09-03', '11:00:00', 'editor-a')]);

        $emailService = $this->createStub(EmailSender::class);
        $emailService->method('send')->willReturn(false);

        $this->service($emailService)->run(self::$now);

        $logFile = self::$kirby->root('logs') . '/scheduledPublish.log';
        $this->assertFileExists($logFile);
        $this->assertStringContainsString('Confirmation email not sent', file_get_contents($logFile));
    }

    public function testNoEmailSentWhenTheEntryHasNoKnownScheduler(): void
    {
        $page = $this->createDraft('email-no-scheduler-page');
        $this->setQueue([$this->entry($page, '2026-09-03', '11:00:00')]);

        $emailService = $this->createMock(EmailSender::class);
        $emailService->expects($this->never())->method('send');

        $this->service($emailService)->run(self::$now);
    }

    public function testNoEmailSentAndNoThrowWhenTheSchedulerNoLongerExists(): void
    {
        $page = $this->createDraft('email-deleted-scheduler-page');
        $this->setQueue([$this->entry($page, '2026-09-03', '11:00:00', 'no-longer-a-user')]);

        $emailService = $this->createMock(EmailSender::class);
        $emailService->expects($this->never())->method('send');

        $result = $this->service($emailService)->run(self::$now);

        $this->assertStringContainsString('Published 1', $result);
    }

    private function service(?EmailSender $emailService = null): ScheduledPublishService
    {
        return new ScheduledPublishService(self::$kirby, $emailService);
    }

    /**
     * Creates a draft page under the site root.
     *
     * @param string $slug The page slug
     * @param array<string, string> $content Extra content fields
     * @return Page The created draft
     */
    private function createDraft(string $slug, array $content = []): Page
    {
        return self::$kirby->impersonate(
            'kirby',
            fn (): Page => self::$kirby->site()->createChild([
                'slug' => $slug,
                'template' => 'default',
                'content' => ['title' => $slug] + $content,
            ])
        );
    }

    /**
     * Builds a queue row for a page.
     *
     * @param Page $page The page the row points at
     * @param string $date The scheduled date (Y-m-d, or '' / malformed for edge cases)
     * @param string $time The scheduled time (H:i:s, or '' / malformed for edge cases)
     * @param string $scheduledBy The scheduler's user id ('' when unknown)
     * @return array<string, mixed> The structure row
     */
    private function entry(Page $page, string $date, string $time, string $scheduledBy = ''): array
    {
        // UUID refs, as the Panel pages field stores them — plain ids do not
        // resolve drafts through toPages().
        return [
            'page' => [$page->uuid()?->toString() ?? $page->id()],
            'scheduledPublishDate' => $date,
            'scheduledPublishTime' => $time,
            'scheduledBy' => $scheduledBy,
        ];
    }

    /**
     * Builds a history row directly.
     *
     * @param string $pageRef The page UUID (does not need to resolve to a real page)
     * @param string $title The title snapshot
     * @param string $scheduledBy The scheduler's user id
     * @param string $publishedAt Y-m-d H:i:s
     * @return array<string, mixed> The structure row
     */
    private function historyRow(string $pageRef, string $title, string $scheduledBy, string $publishedAt): array
    {
        return [
            'page' => [$pageRef],
            'title' => $title,
            'scheduledBy' => $scheduledBy,
            'publishedAt' => $publishedAt,
        ];
    }

    /**
     * Writes the site's scheduled queue directly.
     *
     * @param array<int, array<string, mixed>> $rows Structure rows
     */
    private function setQueue(array $rows): void
    {
        self::$kirby->impersonate(
            'kirby',
            fn () => self::$kirby->site()->update(['scheduled' => Yaml::encode($rows)])
        );
    }

    /**
     * Writes the site's publish history directly.
     *
     * @param array<int, array<string, mixed>> $rows Structure rows
     */
    private function setHistory(array $rows): void
    {
        self::$kirby->impersonate(
            'kirby',
            fn () => self::$kirby->site()->update(['scheduledPublishHistory' => Yaml::encode($rows)])
        );
    }

    /**
     * Reads the current queue rows back from the site field.
     *
     * @return array<int, array<string, mixed>> The queue rows
     */
    private function queueRows(): array
    {
        return $this->siteStructureRows('scheduled');
    }

    /**
     * Reads the current history rows back from the site field.
     *
     * @return array<int, array<string, mixed>> The history rows
     */
    private function historyRows(): array
    {
        return $this->siteStructureRows('scheduledPublishHistory');
    }

    /**
     * @param string $field The site field name
     * @return array<int, array<string, mixed>> The decoded rows
     */
    private function siteStructureRows(string $field): array
    {
        $value = self::$kirby->site()->content()->get($field)->value();
        if ($value === null || trim((string)$value) === '') {
            return [];
        }
        $rows = Yaml::decode((string)$value);
        return is_array($rows) ? array_values($rows) : [];
    }

    /**
     * Reads a row value tolerantly of key casing (Kirby lowercases content keys).
     *
     * @param array<string, mixed> $row The queue row
     * @param string $key The camelCase key
     * @return string The value as a string
     */
    private function rowValue(array $row, string $key): string
    {
        $normalised = array_change_key_case($row);
        return (string)($normalised[strtolower($key)] ?? '');
    }

    /**
     * Re-reads a page from the App, bypassing any stale object state.
     *
     * @param Page $page The page to refresh
     * @return Page The freshly resolved page
     */
    private function refreshed(Page $page): Page
    {
        $found = self::$kirby->site()->childrenAndDrafts()->find($page->slug());
        $this->assertInstanceOf(Page::class, $found);
        return $found;
    }

    /**
     * Stamps site.txt to a known baseline mtime and returns it, so a later
     * read-only {@see self::siteFileMtime()} call can prove whether a write
     * happened. Must be called exactly once, before the action under test.
     */
    private function resetSiteFileMtime(): int
    {
        $siteFile = self::$kirby->site()->root() . '/site.txt';
        touch($siteFile, self::$now->getTimestamp() - 3600);
        clearstatcache(true, $siteFile);
        return filemtime($siteFile);
    }

    /** Read-only: the current mtime of site.txt. */
    private function siteFileMtime(): int
    {
        $siteFile = self::$kirby->site()->root() . '/site.txt';
        clearstatcache(true, $siteFile);
        return filemtime($siteFile);
    }
}
