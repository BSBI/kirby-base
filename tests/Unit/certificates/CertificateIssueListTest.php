<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\certificates;

use BSBI\WebBase\certificates\CertificateIssue;
use BSBI\WebBase\certificates\CertificateIssueList;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CertificateIssue and CertificateIssueList.
 *
 * Covers the stored shape round-tripping intact, looking a record up by context,
 * and the rule that a reissue replaces rather than duplicates — which is what
 * keeps a retried batch run from awarding the same person twice.
 */
final class CertificateIssueListTest extends TestCase
{
    /**
     * Build an issue record.
     *
     * @param string $context The context id
     * @param string $recipient The recipient id
     * @param string $issued The issue date
     * @return CertificateIssue The record
     */
    private function makeIssue(
        string $context = 'course-100-plants',
        string $recipient = 'student-1',
        string $issued = '2026-07-28'
    ): CertificateIssue {
        return new CertificateIssue($recipient, $context, $issued, '100 Plants Challenge');
    }

    /**
     * Verify a record survives being stored and read back.
     */
    public function testIssueRoundTripsThroughItsStoredShape(): void
    {
        $issue = $this->makeIssue();

        $restored = CertificateIssue::fromArray($issue->toArray());

        $this->assertSame($issue->getRecipientId(), $restored->getRecipientId());
        $this->assertSame($issue->getContextId(), $restored->getContextId());
        $this->assertSame($issue->getIssuedOn(), $restored->getIssuedOn());
        $this->assertSame($issue->getTemplateName(), $restored->getTemplateName());
    }

    /**
     * Verify stored data missing keys loads rather than failing.
     *
     * A record written before a field existed must still load, or adding a field
     * would break the dashboard of every student already holding a certificate.
     */
    public function testPartialStoredDataStillLoads(): void
    {
        $issue = CertificateIssue::fromArray(['recipient' => 'student-1', 'context' => 'course-1']);

        $this->assertSame('student-1', $issue->getRecipientId());
        $this->assertSame('', $issue->getTemplateName());
        $this->assertTrue($issue->isValid());
    }

    /**
     * Verify the emailed date survives being stored and read back.
     */
    public function testEmailedDateRoundTripsThroughItsStoredShape(): void
    {
        $issue = new CertificateIssue('student-1', 'course-1', '2026-07-28', 'Design', 'ref123', '2026-08-01');

        $restored = CertificateIssue::fromArray($issue->toArray());

        $this->assertSame('2026-08-01', $restored->getEmailedOn());
        $this->assertTrue($restored->hasBeenEmailed());
    }

    /**
     * Verify an award nobody has been emailed about reports so.
     */
    public function testAnAwardNotYetEmailedReportsThat(): void
    {
        $issue = $this->makeIssue();

        $this->assertSame('', $issue->getEmailedOn());
        $this->assertFalse($issue->hasBeenEmailed());
    }

    /**
     * Verify a record stored before emailedOn existed still loads.
     *
     * Every certificate awarded so far was written without this field, so the
     * absence of it must read as "not emailed" rather than breaking the record.
     */
    public function testARecordStoredBeforeEmailedOnExistedStillLoads(): void
    {
        $issue = CertificateIssue::fromArray([
            'recipient' => 'student-1',
            'context'   => 'course-1',
            'issued'    => '2026-07-28',
            'reference' => 'ref123',
        ]);

        $this->assertTrue($issue->isValid());
        $this->assertFalse($issue->hasBeenEmailed());
    }

    /**
     * Verify recording an email leaves everything else alone.
     */
    public function testRecordingAnEmailKeepsTheRestOfTheRecord(): void
    {
        $issue = (new CertificateIssue('student-1', 'course-1', '2026-07-28', 'Design', 'ref123'))
            ->withEmailedOn('2026-08-05');

        $this->assertSame('2026-08-05', $issue->getEmailedOn());
        $this->assertSame('student-1', $issue->getRecipientId());
        $this->assertSame('course-1', $issue->getContextId());
        $this->assertSame('2026-07-28', $issue->getIssuedOn());
        $this->assertSame('ref123', $issue->getReference());
    }

    /**
     * Verify reissuing clears the emailed date.
     *
     * This is the one non-obvious rule here. A new reference kills every link
     * already sent, so the email the student holds now points at nothing. The
     * question emailedOn exists to answer is "who still needs telling?", and
     * after a reissue the answer for that student is "they do" — keeping the old
     * date would report them as told while leaving them with a dead link.
     */
    public function testReissuingClearsTheEmailedDate(): void
    {
        $issue = (new CertificateIssue('student-1', 'course-1', '2026-07-28', 'Design', 'ref123', '2026-08-01'))
            ->withNewReference();

        $this->assertSame('', $issue->getEmailedOn());
        $this->assertFalse($issue->hasBeenEmailed());
        $this->assertNotSame('ref123', $issue->getReference());
    }

    /**
     * Verify a record with no recipient or context is treated as unusable.
     */
    public function testRecordWithoutRecipientOrContextIsInvalid(): void
    {
        $this->assertFalse((new CertificateIssue('', 'course-1', '2026-07-28'))->isValid());
        $this->assertFalse((new CertificateIssue('student-1', '', '2026-07-28'))->isValid());
    }

    /**
     * Verify unusable records are dropped when a list is built.
     */
    public function testUnusableRecordsAreDroppedFromTheList(): void
    {
        $list = new CertificateIssueList([
            $this->makeIssue(),
            new CertificateIssue('', '', ''),
        ]);

        $this->assertSame(1, $list->count());
    }

    /**
     * Verify a certificate can be found by the context it was awarded for.
     */
    public function testRecordIsFoundByContext(): void
    {
        $list = new CertificateIssueList([
            $this->makeIssue('course-foundations'),
            $this->makeIssue('course-100-plants'),
        ]);

        $this->assertTrue($list->hasForContext('course-100-plants'));
        $this->assertSame('course-100-plants', $list->findForContext('course-100-plants')?->getContextId());
    }

    /**
     * Verify a context with no certificate reports none.
     */
    public function testUnknownContextHasNoRecord(): void
    {
        $list = new CertificateIssueList([$this->makeIssue('course-foundations')]);

        $this->assertFalse($list->hasForContext('course-100-plants'));
        $this->assertNull($list->findForContext('course-100-plants'));
    }

    /**
     * Verify an empty context never matches.
     *
     * Otherwise a record whose context failed to save would match every lookup and
     * show a certificate on every course the student is enrolled on.
     */
    public function testEmptyContextNeverMatches(): void
    {
        $this->assertFalse((new CertificateIssueList([$this->makeIssue()]))->hasForContext(''));
    }

    /**
     * Verify adding a record for a new context keeps the existing ones.
     */
    public function testAddingANewContextKeepsExistingRecords(): void
    {
        $list = (new CertificateIssueList([$this->makeIssue('course-foundations')]))
            ->with($this->makeIssue('course-100-plants'));

        $this->assertSame(2, $list->count());
        $this->assertTrue($list->hasForContext('course-foundations'));
        $this->assertTrue($list->hasForContext('course-100-plants'));
    }

    /**
     * Verify reissuing for the same context replaces rather than duplicates.
     *
     * A batch run retried after a failure would otherwise leave a student holding
     * two records for one course.
     */
    public function testReissueReplacesRatherThanDuplicates(): void
    {
        $list = (new CertificateIssueList([$this->makeIssue('course-1', 'student-1', '2026-01-01')]))
            ->with($this->makeIssue('course-1', 'student-1', '2026-07-28'));

        $this->assertSame(1, $list->count());
        $this->assertSame('2026-07-28', $list->findForContext('course-1')?->getIssuedOn());
    }

    /**
     * Verify removing a context drops that record and keeps the others.
     */
    public function testRemovingAContextKeepsTheOtherRecords(): void
    {
        $list = (new CertificateIssueList([
            $this->makeIssue('course-foundations'),
            $this->makeIssue('course-100-plants'),
        ]))->without('course-100-plants');

        $this->assertSame(1, $list->count());
        $this->assertTrue($list->hasForContext('course-foundations'));
        $this->assertFalse($list->hasForContext('course-100-plants'));
    }

    /**
     * Verify removing the only record leaves an empty list.
     *
     * This is the common case rather than the edge one — most students hold a
     * certificate for a single course — and it is the case where a removal that
     * silently did nothing would be least likely to be noticed.
     */
    public function testRemovingTheOnlyRecordLeavesAnEmptyList(): void
    {
        $list = (new CertificateIssueList([$this->makeIssue('course-1')]))->without('course-1');

        $this->assertSame(0, $list->count());
        $this->assertSame([], $list->toArray());
    }

    /**
     * Verify removing a context that holds no record changes nothing.
     */
    public function testRemovingAnUnknownContextChangesNothing(): void
    {
        $list = (new CertificateIssueList([$this->makeIssue('course-foundations')]))
            ->without('course-100-plants');

        $this->assertSame(1, $list->count());
        $this->assertTrue($list->hasForContext('course-foundations'));
    }

    /**
     * Verify an empty context removes nothing.
     *
     * The mirror of testEmptyContextNeverMatches, and the more dangerous
     * direction: if an empty context matched, removing a certificate for a course
     * whose id failed to resolve would silently wipe every award the student
     * holds rather than none.
     */
    public function testEmptyContextRemovesNothing(): void
    {
        $list = (new CertificateIssueList([
            $this->makeIssue('course-foundations'),
            $this->makeIssue('course-100-plants'),
        ]))->without('');

        $this->assertSame(2, $list->count());
    }

    /**
     * Verify removal leaves the list stored as a list, not as a map.
     *
     * Dropping a record from the middle must not leave a gap in the keys: the
     * stored shape is a sequential array, and a gap would round-trip through YAML
     * as something Kirby reads back differently.
     */
    public function testRemovalLeavesSequentiallyKeyedStoredData(): void
    {
        $list = (new CertificateIssueList([
            $this->makeIssue('course-1'),
            $this->makeIssue('course-2'),
            $this->makeIssue('course-3'),
        ]))->without('course-2');

        $this->assertSame([0, 1], array_keys($list->toArray()));
        $this->assertSame([0, 1], array_keys($list->all()));
    }

    /**
     * Verify a list survives being stored and read back.
     */
    public function testListRoundTripsThroughItsStoredShape(): void
    {
        $list = new CertificateIssueList([
            $this->makeIssue('course-foundations'),
            $this->makeIssue('course-100-plants'),
        ]);

        $restored = CertificateIssueList::fromArray($list->toArray());

        $this->assertSame(2, $restored->count());
        $this->assertTrue($restored->hasForContext('course-foundations'));
    }

    /**
     * Verify an empty list reports nothing.
     */
    public function testEmptyListReportsNothing(): void
    {
        $list = new CertificateIssueList();

        $this->assertSame(0, $list->count());
        $this->assertSame([], $list->all());
        $this->assertSame([], $list->toArray());
    }
}
