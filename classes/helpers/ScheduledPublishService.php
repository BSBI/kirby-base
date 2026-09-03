<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

use DateTimeImmutable;
use DateTimeZone;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Cms\Pages;
use Kirby\Cms\Structure;
use Kirby\Cms\StructureObject;
use Kirby\Content\Content;
use Kirby\Content\Field;
use Kirby\Data\Yaml;
use Throwable;

/**
 * Owns the scheduled-publication queue held in the site's `scheduled`
 * structure field: processing due entries (the cron-hit route), and the
 * per-page bookkeeping (queue/remove) driven by the page hooks.
 *
 * Queue invariant: an entry only leaves the queue when it is RESOLVED — its
 * pages were published, or found already listed. Half-filled rows (page but
 * no date, date but no page) and rows that fail to process are kept, so an
 * editor's work-in-progress survives every run.
 *
 * Options: `scheduledPublish.timezone` (default Europe/London) and
 * `scheduledPublish.token` (when set, the route requires it — see authorise()).
 */
final readonly class ScheduledPublishService
{
    private const string LOG_FILE = 'scheduledPublish';

    private const string DATE_FIELD = 'scheduledPublishDate';

    private const string TIME_FIELD = 'scheduledPublishTime';

    /**
     * @param App $kirby The Kirby application (site, options, permissions)
     */
    public function __construct(private App $kirby)
    {
    }

    /**
     * Checks a caller-supplied token against the `scheduledPublish.token`
     * option. When no token is configured the route stays open (so shipping
     * this code cannot break an existing cron before the option is set).
     *
     * @param string|null $provided The token supplied with the request
     * @return bool True when the request may trigger a run
     */
    public function authorise(?string $provided): bool
    {
        $configured = $this->kirby->option('scheduledPublish.token');
        if (!is_string($configured) || $configured === '') {
            return true;
        }
        return is_string($provided) && hash_equals($configured, $provided);
    }

    /**
     * Processes the queue: publishes every entry whose scheduled moment has
     * passed, keeps everything unresolved, and rewrites the site field only
     * when the queue actually changed.
     *
     * @param DateTimeImmutable|null $now The current moment (injectable for tests);
     *                                    defaults to now in the configured timezone
     * @return string A short report for the route response
     */
    public function run(?DateTimeImmutable $now = null): string
    {
        try {
            $timezone = $this->timezone();
            $now ??= new DateTimeImmutable('now', $timezone);

            $entries = $this->queueEntries();
            if ($entries === null) {
                return '';
            }

            $resolved = [];
            $publishedCount = 0;

            foreach ($entries as $entry) {
                try {
                    $pages = $this->entryPages($entry);
                    $date = $this->fieldValue($entry->content(), self::DATE_FIELD);
                    $time = $this->fieldValue($entry->content(), self::TIME_FIELD);

                    if ($pages->count() === 0 || $date === '') {
                        // Half-filled row — an editor is still working on it.
                        continue;
                    }

                    $due = new DateTimeImmutable($date . ' ' . ($time !== '' ? $time : '00:00:00'), $timezone);
                    if ($now < $due) {
                        continue;
                    }

                    $publishedCount += $this->publishPages($pages, $now);
                    $resolved[] = $entry->content()->toArray();
                } catch (Throwable $e) {
                    // The entry stays queued for retry (only resolved rows are
                    // removed); a bad row must not strand the rest.
                    $this->log('Error processing entry: ' . $e->getMessage());
                }
            }

            $this->removeResolved($resolved);

            return 'Scheduled pages processed. Published ' . $publishedCount . ' pages.';
        } catch (Throwable $e) {
            $this->log('Error: ' . $e->getMessage() . ' - ' . $e->getTraceAsString());
            return 'Error - see ' . self::LOG_FILE . '.log';
        }
    }

    /**
     * Adds or updates the queue entry for a page (one entry per page). An
     * empty time defaults to midnight. A no-op when the values are unchanged,
     * so routine page saves don't rewrite the site file.
     *
     * @param Page $page The page to schedule
     * @param string $date The scheduled date (Y-m-d)
     * @param string $time The scheduled time (empty defaults to 00:00:00)
     */
    public function queue(Page $page, string $date, string $time = ''): void
    {
        $time = $time !== '' ? $time : '00:00:00';
        $entries = $this->queueEntries();
        $rows = [];
        $matched = false;
        $changed = false;

        foreach ($entries ?? [] as $entry) {
            if ($this->isSinglePageEntryFor($entry, $page)) {
                $matched = true;
                $existingDate = $this->fieldValue($entry->content(), self::DATE_FIELD);
                $existingTime = $this->fieldValue($entry->content(), self::TIME_FIELD);
                if ($existingDate === $date && $existingTime === $time) {
                    $rows[] = $entry->content()->toArray();
                    continue;
                }
                $rows[] = $this->rowFor($page, $date, $time);
                $changed = true;
                continue;
            }
            $rows[] = $entry->content()->toArray();
        }

        if (!$matched) {
            $rows[] = $this->rowFor($page, $date, $time);
            $changed = true;
        }

        if ($changed) {
            $this->writeQueue($rows);
        }
    }

    /**
     * Removes the page's own queue entry, if any. Multi-page entries (built
     * by hand in the site tab) are deliberately left alone.
     *
     * @param Page $page The page whose entry should go
     */
    public function remove(Page $page): void
    {
        $entries = $this->queueEntries();
        if ($entries === null) {
            return;
        }

        $rows = [];
        foreach ($entries as $entry) {
            if ($this->isSinglePageEntryFor($entry, $page)) {
                continue;
            }
            $rows[] = $entry->content()->toArray();
        }

        if (count($rows) !== $entries->count()) {
            $this->writeQueue($rows);
        }
    }

    /**
     * Publishes every not-yet-listed page in a due entry's collection.
     *
     * @param \Kirby\Cms\Pages $pages The entry's pages
     * @param DateTimeImmutable $now The current moment, for the log
     * @return int How many pages were actually published
     * @throws Throwable When a page cannot be published (caller keeps the entry)
     */
    private function publishPages(Pages $pages, DateTimeImmutable $now): int
    {
        $published = 0;
        foreach ($pages as $page) {
            if ($page->isListed()) {
                $this->log('Page already published: ' . $page->title() . ' at ' . $now->format('Y-m-d H:i:s'));
                continue;
            }
            $this->kirby->impersonate('kirby', function () use ($page): void {
                $listed = $page->changeStatus('listed');
                $this->clearPageScheduleFields($listed);
            });
            $this->log('Published ' . $page->title() . ' at ' . $now->format('Y-m-d H:i:s'));
            $published++;
        }
        return $published;
    }

    /**
     * Blanks a published page's own schedule fields so the Panel no longer
     * shows it as scheduled. Only writes when there is something to clear.
     *
     * @param Page $page The just-published page
     */
    private function clearPageScheduleFields(Page $page): void
    {
        if (
            $this->fieldValue($page->content(), self::DATE_FIELD) === ''
            && $this->fieldValue($page->content(), self::TIME_FIELD) === ''
        ) {
            return;
        }
        $page->update([
            self::DATE_FIELD => '',
            self::TIME_FIELD => '',
        ]);
    }

    /**
     * Removes the resolved rows from the queue, re-reading it first so that
     * entries added while the run was processing (by an editor, or by the
     * page hooks the run itself triggers) are never clobbered by a write
     * from the run's stale snapshot. Skips the write entirely when nothing
     * needs removing — including when a hook already removed the row.
     *
     * @param array<int, array<string, mixed>> $resolved Rows that left the queue this run
     */
    private function removeResolved(array $resolved): void
    {
        if ($resolved === []) {
            return;
        }

        $entries = $this->queueEntries();
        if ($entries === null) {
            return;
        }

        $rows = [];
        foreach ($entries as $entry) {
            $row = $entry->content()->toArray();
            // Strict: both sides come from the same toArray() parse, so key
            // order and types agree; loose == would let numeric-ish strings
            // ("0" == "00") conflate distinct rows.
            if (in_array($row, $resolved, true)) {
                continue;
            }
            $rows[] = $row;
        }

        if (count($rows) !== $entries->count()) {
            $this->writeQueue($rows);
        }
    }

    /**
     * Reads the queue as a structure, or null when the field is empty.
     *
     * @return Structure|null The queue entries
     */
    private function queueEntries(): ?Structure
    {
        $field = $this->kirby->site()->content()->get('scheduled');
        if (!$field instanceof Field || $field->isEmpty()) {
            return null;
        }
        return $field->toStructure();
    }

    /**
     * Writes the queue rows back to the site field.
     *
     * @param array<int, array<string, mixed>> $rows The rows to keep
     * @throws Throwable When the site cannot be updated
     */
    private function writeQueue(array $rows): void
    {
        $this->kirby->impersonate('kirby', function () use ($rows): void {
            $this->kirby->site()->update([
                'scheduled' => Yaml::encode(array_values($rows)),
            ]);
        });
    }

    /**
     * Whether an entry points at exactly this one page — the shape the
     * per-page hook creates, and the only shape it may modify.
     *
     * @param StructureObject $entry The queue entry
     * @param Page $page The page to match
     * @return bool True when the entry is this page's own
     */
    private function isSinglePageEntryFor(StructureObject $entry, Page $page): bool
    {
        $pages = $this->entryPages($entry);
        // first() is only reached when count() === 1, so it cannot be null.
        return $pages->count() === 1 && $pages->first()->is($page);
    }

    /**
     * Resolves an entry's `page` field to a Pages collection.
     *
     * @param StructureObject $entry The queue entry
     * @return Pages The resolved pages (empty when unresolvable)
     */
    private function entryPages(StructureObject $entry): Pages
    {
        $field = $entry->content()->get('page');
        if (!$field instanceof Field) {
            return new Pages([]);
        }
        return $field->toPages();
    }

    /**
     * Reads a content field's trimmed string value ('' when absent).
     *
     * @param Content $content The content holding the field
     * @param string $name The field name
     * @return string The trimmed value
     */
    private function fieldValue(Content $content, string $name): string
    {
        $field = $content->get($name);
        return $field instanceof Field ? trim($field->toString()) : '';
    }

    /**
     * Builds a queue row for a page, referenced by UUID so the entry
     * survives slug changes.
     *
     * @param Page $page The page to reference
     * @param string $date The scheduled date
     * @param string $time The scheduled time
     * @return array<string, mixed> The structure row
     */
    private function rowFor(Page $page, string $date, string $time): array
    {
        $reference = $page->uuid()->toString();
        return [
            'page' => [$reference],
            self::DATE_FIELD => $date,
            self::TIME_FIELD => $time,
        ];
    }

    /**
     * The queue's timezone, from the `scheduledPublish.timezone` option.
     *
     * @return DateTimeZone The configured timezone (default Europe/London)
     */
    private function timezone(): DateTimeZone
    {
        $configured = $this->kirby->option('scheduledPublish.timezone', 'Europe/London');
        return new DateTimeZone(is_string($configured) && $configured !== '' ? $configured : 'Europe/London');
    }

    /**
     * Appends a message to the scheduled-publish log.
     *
     * @param string $message The message to log
     */
    private function log(string $message): void
    {
        KirbyBaseHelper::writeToLogFile(self::LOG_FILE, $message);
    }
}
