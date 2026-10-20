<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\forms;

use BSBI\WebBase\forms\FormBuilderOptions;
use BSBI\WebBase\Testing\KirbyTestEnvironment;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for FormBuilderOptions: the form type and "Report as" choices offered
 * to editors, and how an editor-typed form type is stored.
 */
final class FormBuilderOptionsTest extends TestCase
{
    private static App $app;

    public static function setUpBeforeClass(): void
    {
        self::$app = KirbyTestEnvironment::boot('kirby-base-form-builder-options-' . uniqid());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function formTypeNames(): array
    {
        return [
            'already snake'     => ['training_feedback', 'training_feedback'],
            'words and capitals' => ['Event Feedback', 'event_feedback'],
            'punctuation runs'  => ['  EDI -- survey (2026) ', 'edi_survey_2026'],
            'hyphens'           => ['agm-vote', 'agm_vote'],
            'nothing usable'    => ['!!!', ''],
        ];
    }

    #[DataProvider('formTypeNames')]
    public function testNormaliseFormType(string $typed, string $stored): void
    {
        $this->assertSame($stored, FormBuilderOptions::normaliseFormType($typed));
    }

    public function testFormTypeOptionsComeFromTheLibraryListByStoredValue(): void
    {
        $this->assertSame(
            ['event_feedback' => 'Event feedback', 'volunteer_survey' => 'Volunteer survey'],
            FormBuilderOptions::formTypeOptions(['Volunteer survey', 'Event feedback', ''], [])
        );
    }

    public function testTypesOnlyOnSubmissionsAreStillOfferedAsStored(): void
    {
        // So a panel-built form can be collated with an older hand-written one.
        $this->assertSame(
            ['agm_vote' => 'agm_vote', 'event_feedback' => 'Event feedback', 'training_feedback' => 'training_feedback'],
            FormBuilderOptions::formTypeOptions(['Event feedback'], ['training_feedback', 'event_feedback', '', 'agm_vote'])
        );
    }

    public function testALibraryLabelWinsOverTheSameTypeInUse(): void
    {
        $this->assertSame(
            ['event_feedback' => 'Event feedback'],
            FormBuilderOptions::formTypeOptions(['Event feedback'], ['event_feedback', 'event_feedback'])
        );
    }

    public function testLibraryNamesThatNormaliseAlikeGiveOneOption(): void
    {
        $this->assertSame(
            ['event_feedback' => 'Event feedback'],
            FormBuilderOptions::formTypeOptions(['Event feedback', 'event-feedback', '!!!'], [])
        );
    }

    public function testFormChoicesAreKeyedByUuidAndNamedWithTheirParent(): void
    {
        $this->assertSame(
            ['page://b' => 'Talk feedback (Events)', 'page://a' => 'Walk feedback (Field meetings)', 'page://c' => 'Walk feedback (Wales)'],
            FormBuilderOptions::formChoices([
                ['uuid' => 'page://c', 'title' => 'Walk feedback', 'parent' => 'Wales'],
                ['uuid' => 'page://a', 'title' => 'Walk feedback', 'parent' => 'Field meetings'],
                ['uuid' => 'page://b', 'title' => 'Talk feedback', 'parent' => 'Events'],
            ])
        );
    }

    public function testATopLevelFormIsNamedWithoutAParent(): void
    {
        $this->assertSame(
            ['page://a' => 'Survey'],
            FormBuilderOptions::formChoices([['uuid' => 'page://a', 'title' => 'Survey', 'parent' => '']])
        );
    }

    public function testReportColumnsAreDistinctTrimmedAndSorted(): void
    {
        $this->assertSame(
            ['email', 'Enjoyed', 'overall'],
            FormBuilderOptions::reportColumns(['overall, Enjoyed', ' email '], ['', 'Enjoyed'], [])
        );
    }

    public function testLibraryWordingTextListsEachSectionsQuestions(): void
    {
        $text = FormBuilderOptions::libraryWordingText([
            ['section' => 'Start', 'questions' => [
                ['label' => 'Member?', 'details' => ['Options: Yes / No', 'Help: Library help']],
                ['label' => 'Comments', 'details' => []],
            ]],
            ['section' => 'End', 'questions' => [
                ['label' => 'Useful?', 'details' => ['Scale: 1 to 5']],
            ]],
        ]);

        $this->assertSame(
            "**Start**\n\n- Member?\n  - Options: Yes / No\n  - Help: Library help\n- Comments\n\n"
            . "**End**\n\n- Useful?\n  - Scale: 1 to 5",
            $text
        );
    }

    public function testLibraryWordingTextEscapesMarkdown(): void
    {
        $text = FormBuilderOptions::libraryWordingText([
            ['section' => 'A *bold* section', 'questions' => [['label' => 'Name_first [1]', 'details' => ['Help: use `x`']]]],
        ]);

        $this->assertStringContainsString('**A \\*bold\\* section**', $text);
        $this->assertStringContainsString('- Name\\_first \\[1\\]', $text);
        $this->assertStringContainsString('Help: use \\`x\\`', $text);
    }

    public function testLibraryWordingTextIsNotReadAsKirbyTags(): void
    {
        // The info box HTML-escapes the text and then runs kirbytext.
        $app = self::$app;
        $text = FormBuilderOptions::libraryWordingText([
            ['section' => 'S', 'questions' => [['label' => 'Go (link: javascript:alert(1) text: x)', 'details' => ['Help: (IMAGE: a.jpg)']]]],
        ]);

        $html = $app->kirbytext((new Page(['slug' => 'p']))->toSafeString($text));

        $this->assertStringNotContainsString('<a', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('link: javascript:alert', $html);
    }

    public function testLibraryWordingTextSaysWhenThereIsNothingToShow(): void
    {
        $this->assertStringContainsString('No library sections', FormBuilderOptions::libraryWordingText([]));
    }
}
