<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

use Closure;
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
 * per-page bookkeeping (queue/remove) driven by the page hooks. Also owns
 * the `scheduledPublishHistory` field: a 30-day-pruned record of pages this
 * service has actually published, and the best-effort confirmation email
 * sent to whoever scheduled each one.
 *
 * Queue invariant: an entry only leaves the queue when it is RESOLVED — its
 * pages were published, or found already listed. Half-filled rows (page but
 * no date, date but no page) and rows that fail to process are kept, so an
 * editor's work-in-progress survives every run.
 *
 * `scheduledBy` is captured only where it is actually knowable: inside
 * queue(), which the per-page Info-tab hook calls with a real editor's
 * session behind it. A row added by hand in the site tab's own structure
 * field has no reliable author to attribute — attributing it to "whoever
 * last saved the site" would be actively wrong, since the site object
 * bundles every unrelated setting — so it is left blank: shown blank in the
 * Panel column, and the confirmation email is simply skipped for it.
 *
 * Options: `scheduledPublish.timezone` (default Europe/London) and
 * `scheduledPublish.token` (when set, the route requires it — see authorise()).
 */
final readonly class ScheduledPublishService
{
    private const string LOG_FILE = 'scheduledPublish';

    private const string QUEUE_FIELD = 'scheduled';

    private const string HISTORY_FIELD = 'scheduledPublishHistory';

    private const string DATE_FIELD = 'scheduledPublishDate';

    private const string TIME_FIELD = 'scheduledPublishTime';

    private const string SCHEDULED_BY_FIELD = 'scheduledBy';

    private const string TITLE_FIELD = 'title';

    private const string PUBLISHED_AT_FIELD = 'publishedAt';

    private const string CONFIRMATION_EMAIL_TEMPLATE = 'scheduled-publish-confirmation';

    private const int HISTORY_RETENTION_DAYS = 30;

    /** Seconds a run may spend publishing before it stops and leaves the rest for the next run. */
    private const int DEFAULT_TIME_BUDGET = 20;

    private const string LOCK_FILE = 'scheduledPublish.lock';

    private EmailSender $emailService;

    /** @var Closure(): float Current time in seconds */
    private Closure $clock;

    private float $timeBudget;

    /**
     * @param App $kirby The Kirby application (site, options, permissions)
     * @param EmailSender|null $emailService The mailer (defaults to a real one; injectable for tests)
     * @param (Closure(): float)|null $clock Seconds now (defaults to microtime; injectable for tests)
     * @param int|float|null $timeBudget Seconds per run (defaults to the
     *                                   `scheduledPublish.timeBudget` option, else 20)
     */
    public function __construct(
        private App $kirby,
        ?EmailSender $emailService = null,
        ?Closure $clock = null,
        int|float|null $timeBudget = null
    ) {
        $this->emailService = $emailService ?? new EmailService($kirby);
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $option = $kirby->option('scheduledPublish.timeBudget');
        $this->timeBudget = (float)($timeBudget
            ?? ((is_int($option) || is_float($option)) && $option > 0 ? $option : self::DEFAULT_TIME_BUDGET));
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
     * passed, keeps everything unresolved, rewrites the site field only when
     * the queue actually changed, and prunes/extends the publish history.
     *
     * @param DateTimeImmutable|null $now The current moment (injectable for tests);
     *                                    defaults to now in the configured timezone
     * @return string A short report for the route response
     */
    public function run(?DateTimeImmutable $now = null): string
    {
        // One run at a time: an overlapping run (a slow one still going when
        // the next cron fires, or a manual hit) would publish the same drafts
        // twice and race the queue writes (bsbi-web#855).
        $busy = false;
        $lock = $this->acquireLock($busy);
        if ($busy) {
            $this->log('Another run is still going; this one did nothing');
            return 'Scheduled publish already running.';
        }
        if ($lock === null) {
            // Can't open the lock file at all: run unlocked rather than never
            // publishing again, and say so.
            $this->log('Could not open the run lock (' . self::LOCK_FILE . ' in the logs folder); running without it');
        }
        // Keep going if the cron's connection drops: a half-finished run is
        // what loses work.
        ignore_user_abort(true);

        try {
            $timezone = $this->timezone();
            $now ??= new DateTimeImmutable('now', $timezone);
            $started = ($this->clock)();

            $entries = $this->queueEntries();
            if ($entries === null) {
                $this->recordHistory([], $now);
                return '';
            }

            $publishedCount = 0;
            $stillDue = 0;
            $startedAnEntry = false;
            $historyWritten = false;
            /** @var list<array{0: Page, 1: string}> $emails */
            $emails = [];

            foreach ($entries as $entry) {
                try {
                    $pages = $this->entryPages($entry);
                    $date = $this->fieldValue($entry->content(), self::DATE_FIELD);
                    $time = $this->fieldValue($entry->content(), self::TIME_FIELD);
                    $scheduledBy = $this->schedulerId($entry);

                    if ($date === '') {
                        // Half-filled row — an editor is still working on it.
                        continue;
                    }

                    $due = new DateTimeImmutable($date . ' ' . ($time !== '' ? $time : '00:00:00'), $timezone);
                    if ($now < $due) {
                        continue;
                    }

                    if ($pages->count() === 0) {
                        // A half-filled row (no page picked yet) is an editor's
                        // work in progress; only a page that can't be found is news.
                        $pageRef = $entry->content()->get('page');
                        $pageValue = $pageRef instanceof Field ? $pageRef->value() : null;
                        $hasPageRef = is_array($pageValue) ? $pageValue !== [] : trim((string)(is_scalar($pageValue) ? $pageValue : '')) !== '';
                        if ($hasPageRef) {
                            $this->log('Queue entry due ' . $date . ' ' . $time . ' points at no page (moved or deleted?); left in the queue');
                        }
                        continue;
                    }

                    // Every run makes progress: the first due entry always goes,
                    // whatever the clock says.
                    if ($startedAnEntry && $this->outOfTime($started)) {
                        $stillDue++;
                        continue;
                    }
                    $startedAnEntry = true;

                    [$historyRows, $complete] = $this->publishPages($pages, $now, $scheduledBy, $started, $emails);
                    $publishedCount += count($historyRows);

                    // Save progress entry by entry, so a run that is cut off
                    // part-way loses nothing (bsbi-web#855).
                    if ($historyRows !== []) {
                        $this->recordHistory($historyRows, $now);
                        $historyWritten = true;
                    }
                    if ($complete) {
                        $this->removeResolved([$entry->content()->toArray()]);
                    } else {
                        $stillDue++;
                    }
                } catch (Throwable $e) {
                    // The entry stays queued for retry (only resolved rows are
                    // removed); a bad row must not strand the rest.
                    $this->log('Error processing entry: ' . $e->getMessage());
                }
            }

            if (!$historyWritten) {
                // Still prune old history on a quiet run.
                $this->recordHistory([], $now);
            }

            // Emails last: a slow mail server can't hold up publishing, and
            // one failure doesn't stop the others.
            foreach ($emails as [$page, $scheduledBy]) {
                try {
                    $this->sendConfirmationEmail($page, $scheduledBy, $now);
                } catch (Throwable $e) {
                    $this->log('Confirmation email failed for ' . $page->title() . ': ' . $e->getMessage());
                }
            }

            $report = 'Scheduled pages processed. Published ' . $publishedCount . ' pages.';
            if ($stillDue > 0) {
                $report .= ' ' . $stillDue . ' still due: out of time, the next run carries on.';
                $this->log('Out of time after ' . rtrim(rtrim(number_format($this->timeBudget, 2, '.', ''), '0'), '.') . 's; ' . $stillDue . ' entr' . ($stillDue === 1 ? 'y' : 'ies') . ' left for the next run');
            }
            return $report;
        } catch (Throwable $e) {
            $this->log('Error: ' . $e->getMessage() . ' - ' . $e->getTraceAsString());
            return 'Error - see ' . self::LOG_FILE . '.log';
        } finally {
            if ($lock !== null) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /**
     * Whether this run has used up its time budget.
     *
     * @param float $started When the run started, from the clock
     * @return bool
     */
    private function outOfTime(float $started): bool
    {
        return (($this->clock)() - $started) >= $this->timeBudget;
    }

    /**
     * Takes the run lock (a file under the logs folder). Returns null with
     * $busy set when another run holds it, and null with $busy unset when the
     * lock file can't be opened at all (the caller then runs unlocked).
     *
     * @param bool $busy Set to true when another run holds the lock
     * @return resource|null
     */
    private function acquireLock(bool &$busy)
    {
        $busy = false;
        $dir = $this->kirby->root('logs');
        if (!is_string($dir) || $dir === '') {
            return null;
        }
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }
        $handle = @fopen($dir . '/' . self::LOCK_FILE, 'c');
        if ($handle === false) {
            return null;
        }
        $wouldBlock = 0;
        if (!flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
            fclose($handle);
            // Only real contention means "already running"; a filesystem that
            // can't lock at all runs unlocked rather than never.
            $busy = $wouldBlock === 1;
            return null;
        }
        return $handle;
    }

    /**
     * Adds or updates the queue entry for a page (one entry per page),
     * recording the current user as its scheduler. An empty time defaults to
     * midnight. A no-op when the date/time are unchanged, so routine page
     * saves don't rewrite the site file or reassign authorship to whoever
     * last happened to save the page.
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
     * Publishes every not-yet-listed page in a due entry's collection, until
     * the time budget runs out. Confirmation emails are collected in $emails
     * and sent after the run's publishing, not here.
     *
     * @param Pages $pages The entry's pages
     * @param DateTimeImmutable $now The current moment, for the log and history
     * @param string $scheduledBy The entry's scheduler user id ('' when unknown)
     * @param float $started When the run started, for the time budget
     * @param list<array{0: Page, 1: string}> $emails Emails to send afterwards (appended to)
     * @return array{0: array<int, array<string, mixed>>, 1: bool} History rows for the pages
     *         published, and whether the whole entry is done
     * @throws Throwable When a page cannot be published (caller keeps the entry)
     */
    private function publishPages(Pages $pages, DateTimeImmutable $now, string $scheduledBy, float $started, array &$emails): array
    {
        $historyRows = [];
        $first = true;
        try {
        foreach ($pages as $page) {
            if ($page->isListed()) {
                $this->log('Page already published: ' . $page->title() . ' at ' . $now->format('Y-m-d H:i:s'));
                continue;
            }
            if (!$first && $this->outOfTime($started)) {
                return [$historyRows, false];
            }
            $first = false;
            $listed = $this->kirby->impersonate('kirby', function () use ($page): Page {
                $listed = $page->changeStatus('listed');
                $this->clearPageScheduleFields($listed);
                return $listed;
            });
            if (!$listed instanceof Page) {
                // impersonate()'s return type is declared `mixed` (it also
                // accepts callbacks with other return shapes); this one
                // always returns Page — asserted for PHPStan and as a guard.
                throw new \LogicException('changeStatus() did not return a Page');
            }
            $this->log('Published ' . $listed->title() . ' at ' . $now->format('Y-m-d H:i:s'));
            $historyRows[] = [
                'page' => [$listed->uuid()->toString()],
                self::TITLE_FIELD => (string)$listed->title(),
                self::SCHEDULED_BY_FIELD => $scheduledBy,
                self::PUBLISHED_AT_FIELD => $now->format('Y-m-d H:i:s'),
            ];
            $emails[] = [$listed, $scheduledBy];
        }
        } catch (Throwable $e) {
            // Keep the history of the pages that did publish before rethrowing,
            // or they'd never get a row: next run sees them already listed.
            if ($historyRows !== []) {
                $this->recordHistory($historyRows, $now);
            }
            throw $e;
        }
        return [$historyRows, true];
    }

    /**
     * Sends the scheduler a best-effort confirmation that their page has
     * been published. Silently skipped (no log) when the entry has no known
     * scheduler — that is the expected shape for a site-tab manual entry,
     * not a failure. Skipped-with-a-log when a scheduler was recorded but
     * cannot be notified (deleted, no email, mailer not configured).
     *
     * @param Page $page The just-published page
     * @param string $scheduledBy The scheduler's user id ('' when unknown)
     * @param DateTimeImmutable $now The current moment
     */
    private function sendConfirmationEmail(Page $page, string $scheduledBy, DateTimeImmutable $now): void
    {
        if ($scheduledBy === '') {
            return;
        }

        $user = $this->kirby->users()->findByKey($scheduledBy);
        if ($user === null) {
            $this->log('No confirmation email: scheduler ' . $scheduledBy . ' not found for ' . $page->title());
            return;
        }

        $email = $user->email();
        if ($email === null || $email === '') {
            $this->log('No confirmation email: scheduler ' . $scheduledBy . ' has no email address');
            return;
        }

        $from = $this->kirby->option('defaultEmail');
        if (!is_string($from) || $from === '') {
            $this->log('No confirmation email sent for ' . $page->title() . ': defaultEmail option not configured');
            return;
        }

        $sent = $this->emailService->send(
            template: self::CONFIRMATION_EMAIL_TEMPLATE,
            from: $from,
            replyTo: $from,
            to: $email,
            subject: 'Your scheduled page has been published: ' . $page->title(),
            data: [
                'title' => (string)$page->title(),
                'url' => $page->url(),
                'publishedAt' => $now->format('Y-m-d H:i:s'),
            ],
        );

        if (!$sent) {
            // Covers both a rejection and a send suppressed by the
            // environment (e.g. local/dev) — EmailService already logs a
            // thrown mailer error; this line is what makes the outcome
            // visible here too, matching every other failure path above.
            $this->log('Confirmation email not sent for ' . $page->title() . ' (to ' . $email . ')');
        }
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
     * Prunes history rows older than the retention window and appends this
     * run's newly-published rows, re-reading first for the same
     * mid-run-collision reason as {@see removeResolved()}. Skips the write
     * when nothing changed. Newest first.
     *
     * @param array<int, array<string, mixed>> $newRows Rows published this run
     * @param DateTimeImmutable $now The current moment
     */
    private function recordHistory(array $newRows, DateTimeImmutable $now): void
    {
        $cutoff = $now->modify('-' . self::HISTORY_RETENTION_DAYS . ' days');
        $existing = $this->historyEntries();

        $rows = [];
        foreach ($existing ?? [] as $entry) {
            $publishedAt = $this->fieldValue($entry->content(), self::PUBLISHED_AT_FIELD);
            if ($publishedAt !== '') {
                try {
                    if (new DateTimeImmutable($publishedAt) < $cutoff) {
                        continue;
                    }
                } catch (Throwable) {
                    // Unparseable — keep it rather than silently losing history.
                }
            }
            $rows[] = $entry->content()->toArray();
        }

        $changed = count($rows) !== ($existing?->count() ?? 0) || $newRows !== [];

        if (!$changed) {
            return;
        }

        array_push($rows, ...$newRows);

        usort(
            $rows,
            fn (array $a, array $b): int => $this->arrayFieldValue($b, self::PUBLISHED_AT_FIELD)
                <=> $this->arrayFieldValue($a, self::PUBLISHED_AT_FIELD)
        );

        $this->kirby->impersonate('kirby', function () use ($rows): void {
            $this->kirby->site()->update([
                self::HISTORY_FIELD => Yaml::encode($rows),
            ]);
        });
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
                self::QUEUE_FIELD => Yaml::encode(array_values($rows)),
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
     * Resolves an entry's `scheduledBy` field to a user id, tolerant of
     * both storage shapes: a bare id (as this service's own rowFor()
     * writes) and a `user://` reference — a plain-string field value can
     * round-trip into the latter once touched through the Panel's `users`
     * field widget (confirmed against a live entry: the widget re-serialises
     * even a single selection as a YAML list of references). Reading that
     * shape as a scalar throws ("Array to string conversion"), which used
     * to strand the whole entry — never published, not just missing its
     * scheduler — since the read happened before the due-check.
     *
     * @param StructureObject $entry The queue entry
     * @return string The scheduler's user id ('' when none recorded/resolvable)
     */
    private function schedulerId(StructureObject $entry): string
    {
        $field = $entry->content()->get(self::SCHEDULED_BY_FIELD);
        if (!$field instanceof Field) {
            return '';
        }
        return $field->toUser()?->id() ?? '';
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
     * Reads a value from a raw structure row array, tolerant of key casing —
     * a row re-read from disk has lowercased keys (Kirby's storage
     * convention), while a row built fresh this run uses the field names as
     * defined above; both may be sorted/compared together in the same pass.
     *
     * @param array<string, mixed> $row The row
     * @param string $name The field name
     * @return string The value as a string
     */
    private function arrayFieldValue(array $row, string $name): string
    {
        $normalised = array_change_key_case($row);
        $value = $normalised[strtolower($name)] ?? '';
        return is_string($value) ? $value : '';
    }

    /**
     * Builds a queue row for a page, referenced by UUID so the entry
     * survives slug changes, recording the current user as scheduler.
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
            self::SCHEDULED_BY_FIELD => $this->kirby->user()?->id() ?? '',
        ];
    }

    /**
     * Reads the queue as a structure, or null when the field is empty.
     *
     * @return Structure|null The queue entries
     */
    private function queueEntries(): ?Structure
    {
        return $this->structureEntries(self::QUEUE_FIELD);
    }

    /**
     * Reads the publish history as a structure, or null when the field is empty.
     *
     * @return Structure|null The history entries
     */
    private function historyEntries(): ?Structure
    {
        return $this->structureEntries(self::HISTORY_FIELD);
    }

    /**
     * Reads a site structure field, or null when it is empty.
     *
     * @param string $name The field name
     * @return Structure|null The entries
     */
    private function structureEntries(string $name): ?Structure
    {
        $field = $this->kirby->site()->content()->get($name);
        if (!$field instanceof Field || $field->isEmpty()) {
            return null;
        }
        return $field->toStructure();
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
