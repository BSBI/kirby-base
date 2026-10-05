<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\forms;

use BSBI\WebBase\forms\FormBuilderOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for FormBuilderOptions: the form type and "Report as" choices offered
 * to editors, and how an editor-typed form type is stored.
 */
final class FormBuilderOptionsTest extends TestCase
{
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

    public function testFormTypesMergeSubmissionAndBuilderTypesDistinctAndSorted(): void
    {
        $this->assertSame(
            ['agm_vote', 'event_feedback', 'Legacy Type', 'training'],
            FormBuilderOptions::formTypes(
                ['training', '', 'event_feedback', 'Legacy Type'],
                ['event_feedback', 'agm_vote', '']
            )
        );
    }

    public function testReportColumnsAreDistinctTrimmedAndSorted(): void
    {
        $this->assertSame(
            ['email', 'Enjoyed', 'overall'],
            FormBuilderOptions::reportColumns(['overall, Enjoyed', ' email '], ['', 'Enjoyed'], [])
        );
    }
}
