<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\forms;

use BSBI\WebBase\forms\FormSubmissionExporter;
use BSBI\WebBase\Testing\KirbyContentBuilder;
use BSBI\WebBase\Testing\KirbyTestEnvironment;
use Kirby\Data\Data;
use PHPUnit\Framework\TestCase;

/**
 * Tests for FormSubmissionExporter: the rows behind the submissions CSVs.
 */
final class FormSubmissionExporterTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        KirbyTestEnvironment::boot('kirby-base-form-submission-exporter-' . uniqid());
    }

    public function testRecordReadsASubmissionPage(): void
    {
        $page = (new KirbyContentBuilder())->page([
            'title'      => 'Submission: Jan-1-10.00.00',
            'form_type'  => 'feedback',
            'submission' => Data::encode([
                ['question' => 'Name', 'answer' => 'Ann'],
                ['question' => 'Email', 'answer' => 'a@example.org', 'key' => 'email', 'column' => 'email'],
            ], 'yaml'),
        ]);

        $record = FormSubmissionExporter::record($page);

        $this->assertSame('feedback', $record['formType']);
        $this->assertSame('Submission: Jan-1-10.00.00', $record['title']);
        $this->assertSame([
            ['question' => 'Name', 'answer' => 'Ann'],
            ['question' => 'Email', 'answer' => 'a@example.org', 'key' => 'email', 'column' => 'email'],
        ], $record['items']);
    }

    public function testRecordOfAPageWithNoSubmissionHasNoItems(): void
    {
        $record = FormSubmissionExporter::record((new KirbyContentBuilder())->page(['title' => 'Empty']));

        $this->assertSame('', $record['formType']);
        $this->assertSame([], $record['items']);
    }

    public function testLegacySubmissionsGiveTheSameRowsAsBefore(): void
    {
        // What the routes built before this class existed: columns by question,
        // in first-seen order, later questions appended on the right.
        $rows = (new FormSubmissionExporter())->wide([
            $this->submission('Submission: A', [['question' => 'Name', 'answer' => 'Ann']], 'feedback'),
            $this->submission('Submission: B', [
                ['question' => 'Email', 'answer' => 'b@example.org'],
                ['question' => 'Name', 'answer' => 'Bob'],
            ], ''),
        ], true);

        $this->assertSame([
            ['Form Type', 'Submission', 'Name', 'Email'],
            ['feedback', 'Submission: A', 'Ann', ''],
            ['(untyped)', 'Submission: B', 'Bob', 'b@example.org'],
        ], $rows);
    }

    public function testWithoutFormTypeTheFirstColumnIsLeftOut(): void
    {
        $rows = (new FormSubmissionExporter())->wide([
            $this->submission('Submission: A', [['question' => 'Name', 'answer' => 'Ann']]),
        ], false);

        $this->assertSame([['Submission', 'Name'], ['Submission: A', 'Ann']], $rows);
    }

    public function testItemsWithAColumnLineUpByColumnNotByLabel(): void
    {
        $rows = (new FormSubmissionExporter())->wide([
            $this->submission('S1', [$this->item('Your email', 'a@example.org', 'email')], 'f', '2026-01-01 10:00:00'),
            $this->submission('S2', [$this->item('Email address', 'b@example.org', 'email')], 'f', '2026-02-01 10:00:00'),
        ], false);

        $this->assertSame([
            ['Submission', 'Email address'],
            ['S1', 'a@example.org'],
            ['S2', 'b@example.org'],
        ], $rows);
    }

    public function testTheHeaderIsTheMostRecentLabelWhateverTheOrder(): void
    {
        $rows = (new FormSubmissionExporter())->wide([
            $this->submission('Newer', [$this->item('New label', 'x', 'q')], 'f', '2026-03-01 10:00:00'),
            $this->submission('Older', [$this->item('Old label', 'y', 'q')], 'f', '2026-01-01 10:00:00'),
        ], false);

        $this->assertSame(['Submission', 'New label'], $rows[0]);
    }

    public function testReportAsNamesTheColumnAndMergesKeys(): void
    {
        $rows = (new FormSubmissionExporter())->wide([
            $this->submission('S1', [$this->item('Did you enjoy the walk?', 'Yes', 'f_aaaa1111', 'enjoyed')]),
            $this->submission('S2', [$this->item('Did you enjoy the talk?', 'No', 'f_bbbb2222', 'enjoyed')]),
        ], false);

        $this->assertSame([
            ['Submission', 'enjoyed'],
            ['S1', 'Yes'],
            ['S2', 'No'],
        ], $rows);
    }

    public function testLegacyAndNewItemsSitSideBySide(): void
    {
        $rows = (new FormSubmissionExporter())->wide([
            $this->submission('Old', [['question' => 'Email', 'answer' => 'old@example.org']]),
            $this->submission('New', [$this->item('Email', 'new@example.org', 'email')]),
        ], false);

        // A legacy "Email" question and a keyed "email" field are different
        // columns, even when their headers read the same.
        $this->assertSame([
            ['Submission', 'Email', 'Email'],
            ['Old', 'old@example.org', ''],
            ['New', '', 'new@example.org'],
        ], $rows);
    }

    public function testLongFormatHasOneRowPerAnswer(): void
    {
        $rows = (new FormSubmissionExporter())->long([
            $this->submission('S1', [
                $this->item('Did you enjoy it?', 'Yes', 'f_aaaa1111', 'enjoyed'),
                $this->item('Email', 'a@example.org', 'email'),
            ], 'feedback', '2026-01-01 10:00:00'),
            $this->submission('S2', [['question' => 'Name', 'answer' => 'Bob']], '', '2026-01-02 09:00:00'),
        ]);

        $this->assertSame([
            ['Form Type', 'Submission', 'Date', 'Column', 'Question', 'Answer'],
            ['feedback', 'S1', '2026-01-01 10:00:00', 'enjoyed', 'Did you enjoy it?', 'Yes'],
            ['feedback', 'S1', '2026-01-01 10:00:00', 'email', 'Email', 'a@example.org'],
            ['(untyped)', 'S2', '2026-01-02 09:00:00', 'Name', 'Name', 'Bob'],
        ], $rows);
    }

    public function testNonStringValuesBecomeStrings(): void
    {
        $rows = (new FormSubmissionExporter())->wide([
            $this->submission('S1', [['question' => 'Count', 'answer' => 3], ['question' => 'Empty', 'answer' => null]]),
        ], false);

        $this->assertSame(['S1', '3', ''], $rows[1]);
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array{formType: string, title: string, date: string, items: list<array<string, mixed>>}
     */
    private function submission(string $title, array $items, string $formType = '', string $date = ''): array
    {
        return ['formType' => $formType, 'title' => $title, 'date' => $date, 'items' => $items];
    }

    /**
     * @return array<string, string>
     */
    private function item(string $question, string $answer, string $key, ?string $column = null): array
    {
        return ['question' => $question, 'answer' => $answer, 'key' => $key, 'column' => $column ?? $key];
    }
}
