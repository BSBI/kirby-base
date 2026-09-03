<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\ScheduledPublishService;
use BSBI\WebBase\Testing\KirbyTestEnvironment;
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
 */
final class ScheduledPublishServiceTest extends TestCase
{
    private static App $kirby;

    /** A moment the tests treat as "now". */
    private static DateTimeImmutable $now;

    public static function setUpBeforeClass(): void
    {
        self::$kirby = KirbyTestEnvironment::boot('kirby-base-scheduled-publish-' . uniqid());
        self::$now = new DateTimeImmutable('2026-09-03 12:00:00', new DateTimeZone('Europe/London'));
    }

    protected function setUp(): void
    {
        $this->setQueue([]);
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
        $siteFile = self::$kirby->site()->root() . '/site.txt';
        touch($siteFile, self::$now->getTimestamp() - 3600);
        clearstatcache(true, $siteFile);
        $before = filemtime($siteFile);

        $this->service()->run(self::$now);

        clearstatcache(true, $siteFile);
        $this->assertSame($before, filemtime($siteFile), 'site.txt must not be rewritten when the queue is unchanged');
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
        $siteFile = self::$kirby->site()->root() . '/site.txt';
        touch($siteFile, self::$now->getTimestamp() - 3600);
        clearstatcache(true, $siteFile);
        $before = filemtime($siteFile);

        $this->service()->queue($page, '2026-10-01', '08:30:00');

        clearstatcache(true, $siteFile);
        $this->assertSame($before, filemtime($siteFile), 'an unchanged upsert must not rewrite site.txt');
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

    private function service(): ScheduledPublishService
    {
        return new ScheduledPublishService(self::$kirby);
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
     * @return array<string, mixed> The structure row
     */
    private function entry(Page $page, string $date, string $time): array
    {
        // UUID refs, as the Panel pages field stores them — plain ids do not
        // resolve drafts through toPages().
        return [
            'page' => [$page->uuid()?->toString() ?? $page->id()],
            'scheduledPublishDate' => $date,
            'scheduledPublishTime' => $time,
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
     * Reads the current queue rows back from the site field.
     *
     * @return array<int, array<string, mixed>> The queue rows
     */
    private function queueRows(): array
    {
        $value = self::$kirby->site()->content()->get('scheduled')->value();
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
}
