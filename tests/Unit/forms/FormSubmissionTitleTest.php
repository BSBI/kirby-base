<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\forms;

use BSBI\WebBase\forms\FormSubmissionTitle;
use BSBI\WebBase\forms\about\AboutSubject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for FormSubmissionTitle: what a response's title names after its
 * submission time, so a form's responses can be told apart in the panel.
 */
final class FormSubmissionTitleTest extends TestCase
{
    private const FILL_FROM = ['event_name' => 'title', 'event_date' => 'date'];

    public function testAnAboutLinkNamesItsSubjectAndFormatsItsDate(): void
    {
        $about = new AboutSubject('page://walk', 'Spring botany walk', '2026-05-12', 'signed');

        $described = FormSubmissionTitle::describe([], self::FILL_FROM, $about);

        $this->assertSame('Spring botany walk (12 May 2026)', $described);
    }

    public function testASubjectWithoutADateIsNamedAlone(): void
    {
        $about = new AboutSubject('page://walk', 'Spring botany walk', '', 'signed');

        $this->assertSame('Spring botany walk', FormSubmissionTitle::describe([], self::FILL_FROM, $about));
    }

    public function testWithoutALinkTheAnswersToTheTitleAndDateQuestionsAreUsed(): void
    {
        $items = [
            ['question' => 'Your name', 'answer' => 'Ann', 'key' => 'full_name'],
            ['question' => 'Event?', 'answer' => '  Spring   botany walk ', 'key' => 'event_name'],
            ['question' => 'Date?', 'answer' => '12/5/26', 'key' => 'event_date'],
        ];

        $this->assertSame('Spring botany walk (12/5/26)', FormSubmissionTitle::describe($items, self::FILL_FROM, null));
    }

    public function testADateAnsweredWithADatePickerIsFormattedLikeALinksDate(): void
    {
        $items = [
            ['question' => 'Event?', 'answer' => 'Spring botany walk', 'key' => 'event_name'],
            ['question' => 'Date?', 'answer' => '2026-05-12', 'key' => 'event_date'],
        ];

        $described = FormSubmissionTitle::describe($items, self::FILL_FROM, null);

        $this->assertSame('Spring botany walk (12 May 2026)', $described);
    }

    public function testTheFirstAnswerToATitleQuestionIsUsed(): void
    {
        $items = [
            ['question' => 'Event?', 'answer' => 'First walk', 'key' => 'event_name'],
            ['question' => 'Course?', 'answer' => 'Second walk', 'key' => 'course_name'],
        ];

        $fillFrom = ['event_name' => 'title', 'course_name' => 'title'];

        $this->assertSame('First walk', FormSubmissionTitle::describe($items, $fillFrom, null));
    }

    public function testAnswersWithoutKeysNameNothing(): void
    {
        $items = [['question' => 'Event?', 'answer' => 'Walk']];

        $this->assertSame('', FormSubmissionTitle::describe($items, self::FILL_FROM, null));
    }

    public function testOnlyWhatIsKnownIsNamed(): void
    {
        $dateOnly = [['question' => 'Date?', 'answer' => '12 May', 'key' => 'event_date']];
        $blank = [['question' => 'Event?', 'answer' => '', 'key' => 'event_name']];

        $this->assertSame('12 May', FormSubmissionTitle::describe($dateOnly, self::FILL_FROM, null));
        $this->assertSame('', FormSubmissionTitle::describe($blank, self::FILL_FROM, null));
        $this->assertSame('', FormSubmissionTitle::describe($dateOnly, [], null), 'no title or date questions');
    }

    public function testInvisibleAndDirectionCharactersAreDropped(): void
    {
        $answer = "Spring\u{202E}klaw\u{200B} walk\u{0007}";
        $items = [['question' => 'Event?', 'answer' => $answer, 'key' => 'event_name']];

        $this->assertSame('Springklaw walk', FormSubmissionTitle::describe($items, self::FILL_FROM, null));
    }

    public function testALongNameIsShortened(): void
    {
        $items = [['question' => 'Event?', 'answer' => str_repeat('Botany ', 30), 'key' => 'event_name']];

        $described = FormSubmissionTitle::describe($items, self::FILL_FROM, null);

        $this->assertSame(FormSubmissionTitle::MAX_LENGTH, mb_strlen($described));
        $this->assertStringEndsWith('…', $described);
    }

    public function testATitleNamesTheSubjectAfterTheTimeAndCanBeRenamed(): void
    {
        $named = FormSubmissionTitle::with('Submission: Oct-20-14.03.12', 'Spring walk (12 May 2026)');

        $this->assertSame('Submission: Oct-20-14.03.12 — Spring walk (12 May 2026)', $named);
        $renamed = FormSubmissionTitle::with($named, 'Autumn walk');
        $this->assertSame('Submission: Oct-20-14.03.12 — Autumn walk', $renamed);
        $this->assertSame('Submission: Oct-20-14.03.12', FormSubmissionTitle::with($named, ''));
    }
}
