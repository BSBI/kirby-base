<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\forms\panel;

use BSBI\WebBase\forms\BaseFormDefinition;
use BSBI\WebBase\forms\FormBuilderOptions;
use BSBI\WebBase\forms\FormFieldSpec;
use BSBI\WebBase\forms\FormSection;
use BSBI\WebBase\forms\panel\PanelFormDefinition;
use BSBI\WebBase\forms\ResolvedFormSection;
use BSBI\WebBase\Testing\KirbyTestEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PanelFormDefinition: a form page's section blocks become the same
 * resolved form a hand-written BaseFormDefinition gives.
 */
final class PanelFormDefinitionTest extends TestCase
{
    use PanelFormFixtures;

    public static function setUpBeforeClass(): void
    {
        KirbyTestEnvironment::boot('kirby-base-panel-form-definition-' . uniqid());
    }

    public function testFormTypeIsPassedThrough(): void
    {
        $definition = new PanelFormDefinition($this->formPage([]), 'my_form', $this->resolver([]));
        $this->assertSame('my_form', $definition->getFormType());
    }

    public function testMatchesTheEquivalentHandWrittenDefinition(): void
    {
        $contact = $this->sectionPage([
            $this->blockData('form-textbox', ['label' => 'Email', 'name' => 'email', 'required' => 'true', 'inputType' => 'email']),
            $this->blockData('form-radio-group', ['label' => 'Contact me by', 'name' => 'contact_by', 'options' => "Email\nPhone"]),
        ], legend: 'Contact details', slug: 'contact');

        $form = $this->formPage([
            $this->sectionRef('page://contact', id: 'sec-1'),
            $this->sectionInline(
                [$this->blockData('form-textbox', ['label' => 'Phone number', 'name' => 'phone'])],
                title: 'Phone',
                showField: 'contact_by',
                showValue: 'Phone',
                id: 'sec-2'
            ),
        ]);

        $panel = new PanelFormDefinition($form, 'contact', $this->resolver(['page://contact' => $contact]));

        $handWritten = $this->handWritten([
            FormSection::make('sec-1', 'Contact details')->fields(
                FormFieldSpec::textbox('email', 'Email', 'email')->required(),
                FormFieldSpec::radioGroup('contact_by', 'Contact me by', ['Email', 'Phone']),
            ),
            FormSection::make('sec-2', 'Phone')
                ->fields(FormFieldSpec::textbox('phone', 'Phone number'))
                ->showWhen('contact_by', 'Phone'),
        ]);

        $this->assertEquals($handWritten->getFieldGroups($form), $panel->getFieldGroups($form));
        $this->assertSame(['email', 'contact_by', 'phone'], $panel->getFieldNames());
        $this->assertSame([], $panel->validate());
    }

    public function testRefTitleOverridesTheSectionLegend(): void
    {
        $section = $this->sectionPage([$this->blockData('form-textarea', ['label' => 'N', 'name' => 'n'])], legend: 'Library legend', slug: 's');
        $form = $this->formPage([$this->sectionRef('page://s', title: 'On this form')]);

        $groups = (new PanelFormDefinition($form, 't', $this->resolver(['page://s' => $section])))->getFieldGroups($form);

        $this->assertInstanceOf(ResolvedFormSection::class, $groups[0]);
        $this->assertSame('On this form', $groups[0]->title);
    }

    public function testSameSectionOnTwoFormsGivesTheSameKeys(): void
    {
        $section = $this->sectionPage([
            $this->blockData('form-textbox', ['label' => 'Email'], '11111111-2222-4333-8444-555555555555'),
        ], slug: 's');
        $resolver = $this->resolver(['page://s' => $section]);

        $one = new PanelFormDefinition($this->formPage([$this->sectionRef('page://s')]), 't', $resolver);
        $two = new PanelFormDefinition($this->formPage([$this->sectionRef('page://s')]), 't', $resolver);

        $this->assertSame(['f_11111111'], $one->getFieldNames());
        $this->assertSame($one->getFieldNames(), $two->getFieldNames());
    }

    public function testMissingSectionIsSkippedAndReported(): void
    {
        $form = $this->formPage([
            $this->sectionRef('page://gone'),
            $this->sectionInline([$this->blockData('form-textbox', ['label' => 'Name', 'name' => 'name'])]),
        ]);
        $definition = new PanelFormDefinition($form, 't', $this->resolver([]));

        $this->assertSame(['name'], $definition->getFieldNames());
        $this->assertStringContainsString('cannot be found', $definition->validate()[0]);
    }

    public function testRefWithNoSectionChosenIsReported(): void
    {
        $form = $this->formPage([$this->blockData('form-section-ref', ['section' => ''])]);
        $definition = new PanelFormDefinition($form, 't', $this->resolver([]));

        $this->assertSame([], $definition->getFieldNames());
        $this->assertStringContainsString('no section chosen', $definition->validate()[0]);
    }

    public function testDuplicateKeyKeepsTheFirstAndReports(): void
    {
        $form = $this->formPage([
            $this->sectionInline([$this->blockData('form-textbox', ['label' => 'Email', 'name' => 'email'])]),
            $this->sectionInline([$this->blockData('form-textarea', ['label' => 'Email again', 'name' => 'email'])]),
        ]);
        $definition = new PanelFormDefinition($form, 't', $this->resolver([]));

        $this->assertSame(['email'], $definition->getFieldNames());
        $groups = $definition->getFieldGroups($form);
        $this->assertInstanceOf(ResolvedFormSection::class, $groups[1]);
        $this->assertSame([], $groups[1]->fields);
        $this->assertStringContainsString('"email" is used more than once', $definition->validate()[0]);
    }

    public function testInvalidNameIsDroppedAndReported(): void
    {
        $form = $this->formPage([
            $this->sectionInline([$this->blockData('form-textbox', ['label' => 'Name', 'name' => 'first name'])]),
        ]);
        $definition = new PanelFormDefinition($form, 't', $this->resolver([]));

        $this->assertSame([], $definition->getFieldNames());
        $this->assertStringContainsString('"first name"', $definition->validate()[0]);
    }

    public function testConditionOnUnknownFieldIsDroppedAndReported(): void
    {
        $form = $this->formPage([
            $this->sectionInline(
                [$this->blockData('form-textbox', ['label' => 'Phone', 'name' => 'phone'])],
                showField: 'nope',
                showValue: 'Phone'
            ),
        ]);
        $definition = new PanelFormDefinition($form, 't', $this->resolver([]));

        $groups = $definition->getFieldGroups($form);
        $this->assertInstanceOf(ResolvedFormSection::class, $groups[0]);
        $this->assertFalse($groups[0]->isConditional());
        $this->assertStringContainsString('"nope"', $definition->validate()[0]);
    }

    public function testConditionOnALaterFieldIsDropped(): void
    {
        $form = $this->formPage([
            $this->sectionInline(
                [$this->blockData('form-textbox', ['label' => 'Phone', 'name' => 'phone'])],
                showField: 'contact_by',
                showValue: 'Phone'
            ),
            $this->sectionInline([
                $this->blockData('form-radio-group', ['label' => 'By', 'name' => 'contact_by', 'options' => "Email\nPhone"]),
            ]),
        ]);
        $definition = new PanelFormDefinition($form, 't', $this->resolver([]));

        $groups = $definition->getFieldGroups($form);
        $this->assertInstanceOf(ResolvedFormSection::class, $groups[0]);
        $this->assertFalse($groups[0]->isConditional());
        $this->assertStringContainsString('earlier', $definition->validate()[0]);
    }

    public function testConditionOnANonChoiceFieldIsDropped(): void
    {
        $form = $this->formPage([
            $this->sectionInline([$this->blockData('form-textbox', ['label' => 'Name', 'name' => 'name'])]),
            $this->sectionInline(
                [$this->blockData('form-textbox', ['label' => 'X', 'name' => 'x'])],
                showField: 'name',
                showValue: 'Bob'
            ),
        ]);
        $definition = new PanelFormDefinition($form, 't', $this->resolver([]));

        $groups = $definition->getFieldGroups($form);
        $this->assertInstanceOf(ResolvedFormSection::class, $groups[1]);
        $this->assertFalse($groups[1]->isConditional());
        $this->assertStringContainsString('radio or dropdown', $definition->validate()[0]);
    }

    public function testConditionValueMustBeOneOfTheOptions(): void
    {
        $form = $this->formPage([
            $this->sectionInline([
                $this->blockData('form-select', ['label' => 'By', 'name' => 'by', 'options' => "Email\nPhone"]),
            ]),
            $this->sectionInline(
                [$this->blockData('form-textbox', ['label' => 'X', 'name' => 'x'])],
                showField: 'by',
                showValue: 'Post'
            ),
        ]);
        $definition = new PanelFormDefinition($form, 't', $this->resolver([]));

        $groups = $definition->getFieldGroups($form);
        $this->assertInstanceOf(ResolvedFormSection::class, $groups[1]);
        $this->assertFalse($groups[1]->isConditional());
        $this->assertStringContainsString('"Post"', $definition->validate()[0]);
    }

    public function testConditionValueWithAnAmpersandMatchesTheRawOption(): void
    {
        $form = $this->formPage([
            $this->sectionInline([
                $this->blockData('form-radio-group', ['label' => 'By', 'name' => 'by', 'options' => "Email & post\nPhone"]),
            ]),
            $this->sectionInline(
                [$this->blockData('form-textbox', ['label' => 'X', 'name' => 'x'])],
                showField: 'by',
                showValue: 'Email & post'
            ),
        ]);
        $definition = new PanelFormDefinition($form, 't', $this->resolver([]));

        $groups = $definition->getFieldGroups($form);
        $this->assertInstanceOf(ResolvedFormSection::class, $groups[1]);
        $this->assertSame('Email & post', $groups[1]->conditionValue);
        $this->assertSame([], $definition->validate());
    }

    public function testConditionNeedsBothFieldAndValue(): void
    {
        $form = $this->formPage([
            $this->sectionInline([
                $this->blockData('form-radio-group', ['label' => 'By', 'name' => 'by', 'options' => "Email\nPhone"]),
            ]),
            $this->sectionInline(
                [$this->blockData('form-textbox', ['label' => 'X', 'name' => 'x'])],
                showField: 'by'
            ),
        ]);
        $definition = new PanelFormDefinition($form, 't', $this->resolver([]));

        $groups = $definition->getFieldGroups($form);
        $this->assertInstanceOf(ResolvedFormSection::class, $groups[1]);
        $this->assertFalse($groups[1]->isConditional());
        $this->assertStringContainsString('both', $definition->validate()[0]);
    }

    public function testInfoFieldsAreNotSubmittedAndDoNotClashWithKeys(): void
    {
        $form = $this->formPage([
            $this->sectionInline([
                $this->blockData('form-info', ['text' => 'Hello']),
                $this->blockData('form-textbox', ['label' => 'Name', 'name' => 'name']),
            ]),
        ]);
        $definition = new PanelFormDefinition($form, 't', $this->resolver([]));

        $this->assertSame(['name'], $definition->getFieldNames());
        $this->assertSame([], $definition->validate());
    }

    public function testFormPageWithoutTheFieldGivesAnEmptyForm(): void
    {
        $page = (new \BSBI\WebBase\Testing\KirbyContentBuilder())->page([]);
        $definition = new PanelFormDefinition($page, 't', $this->resolver([]));

        $this->assertSame([], $definition->getFieldGroups($page));
        $this->assertSame([], $definition->validate());
    }

    public function testConditionChoicesListEveryAnswerOfEveryRadioAndDropdown(): void
    {
        $contact = $this->sectionPage([
            $this->blockData('form-radio-group', ['label' => 'Contact me by', 'name' => 'contact_by', 'options' => "Email\nPhone"]),
            $this->blockData('form-textbox', ['label' => 'Email', 'name' => 'email']),
        ], legend: 'Contact details', slug: 'contact');

        $form = $this->formPage([
            $this->sectionRef('page://contact'),
            $this->sectionInline([
                $this->blockData('form-select', ['label' => 'Visit', 'options' => "Fish & chips\nTea: green"], 'abcdef12-0000-4000-8000-000000000000'),
                $this->blockData('form-checkbox-group', ['label' => 'Topics', 'name' => 'topics', 'options' => "A\nB"]),
            ]),
        ]);

        $choices = (new PanelFormDefinition($form, 't', $this->resolver(['page://contact' => $contact])))->conditionChoices();

        $this->assertSame([
            'contact_by:Email'         => 'Contact me by: Email (Contact details)',
            'contact_by:Phone'         => 'Contact me by: Phone (Contact details)',
            'f_abcdef12:Fish & chips'  => 'Visit: Fish & chips (Section 2)',
            'f_abcdef12:Tea: green'    => 'Visit: Tea: green (Section 2)',
        ], $choices);
    }

    public function testConditionChoicesForAPageAreBuiltOncePerRequest(): void
    {
        $form = $this->formPage([
            $this->sectionInline([
                $this->blockData('form-radio-group', ['label' => 'By', 'name' => 'by', 'options' => "Email"]),
            ], title: 'Contact'),
        ]);

        $first = FormBuilderOptions::conditionChoicesFor($form);
        $this->assertSame(['by:Email' => 'By: Email (Contact)'], $first);
        $this->assertSame($first, FormBuilderOptions::conditionChoicesFor($form));
    }

    public function testConditionChoicesLeaveOutDroppedQuestions(): void
    {
        $form = $this->formPage([
            $this->sectionInline([
                $this->blockData('form-radio-group', ['label' => 'First', 'name' => 'dup', 'options' => "Yes\nNo"]),
                $this->blockData('form-radio-group', ['label' => 'Second', 'name' => 'dup', 'options' => "Maybe"]),
                $this->blockData('form-radio-group', ['label' => 'Bad', 'name' => '9bad', 'options' => "X"]),
            ], title: 'Q'),
        ]);

        $choices = (new PanelFormDefinition($form, 't', $this->resolver([])))->conditionChoices();

        $this->assertSame(['dup:Yes' => 'First: Yes (Q)', 'dup:No' => 'First: No (Q)'], $choices);
    }

    public function testAStoredConditionNoLongerOnTheFormStaysChoosableAndMarked(): void
    {
        // Otherwise the select's stored value would not be one of its options,
        // and the panel could refuse to save the page.
        $form = $this->formPage([
            $this->sectionInline([
                $this->blockData('form-radio-group', ['label' => 'By', 'name' => 'by', 'options' => "Email\nPhone"]),
            ], title: 'Contact'),
            $this->blockData('form-section-inline', ['title' => 'X', 'formFields' => '[]', 'showWhen' => 'by:Post']),
        ]);
        $definition = new PanelFormDefinition($form, 't', $this->resolver([]));

        $choices = $definition->conditionChoices();
        $this->assertSame('No longer on this form: by:Post', $choices['by:Post']);
        // Also for a section left out of the form (its library section is gone).
        $form = $this->formPage([
            $this->blockData('form-section-ref', ['section' => '- page://gone', 'showWhen' => 'by:Email']),
        ]);
        $this->assertSame(
            ['by:Email' => 'No longer on this form: by:Email'],
            (new PanelFormDefinition($form, 't', $this->resolver([])))->conditionChoices()
        );
        $this->assertSame('By: Email (Contact)', $choices['by:Email']);
        $this->assertNotSame([], $definition->validate());
    }

    public function testShowWhenIsReadAsKeyAndAnswerSplitAtTheFirstColon(): void
    {
        $form = $this->formPage([
            $this->sectionInline([
                $this->blockData('form-radio-group', ['label' => 'When', 'name' => 'when', 'options' => "Time: morning\nTime: evening"]),
            ]),
            $this->blockData('form-section-inline', [
                'title'      => 'Morning details',
                'formFields' => '[]',
                'showWhen'   => 'when:Time: morning',
            ], 'sec-2'),
        ]);
        $definition = new PanelFormDefinition($form, 't', $this->resolver([]));

        $groups = $definition->getFieldGroups($form);
        $this->assertInstanceOf(ResolvedFormSection::class, $groups[1]);
        $this->assertSame('when', $groups[1]->conditionField);
        $this->assertSame('Time: morning', $groups[1]->conditionValue);
        $this->assertSame([], $definition->validate());
    }

    public function testShowWhenTakesPrecedenceOverTheOldFields(): void
    {
        $form = $this->formPage([
            $this->sectionInline([
                $this->blockData('form-radio-group', ['label' => 'By', 'name' => 'by', 'options' => "Email\nPhone"]),
            ]),
            $this->blockData('form-section-inline', [
                'title'         => 'X',
                'formFields'    => '[]',
                'showWhen'      => 'by:Phone',
                'showWhenField' => 'by',
                'showWhenValue' => 'Email',
            ]),
        ]);
        $definition = new PanelFormDefinition($form, 't', $this->resolver([]));

        $groups = $definition->getFieldGroups($form);
        $this->assertInstanceOf(ResolvedFormSection::class, $groups[1]);
        $this->assertSame('Phone', $groups[1]->conditionValue);
    }

    public function testAShowWhenWithoutAnAnswerIsReported(): void
    {
        $form = $this->formPage([
            $this->sectionInline([
                $this->blockData('form-radio-group', ['label' => 'By', 'name' => 'by', 'options' => "Email\nPhone"]),
            ]),
            $this->blockData('form-section-inline', ['title' => 'X', 'formFields' => '[]', 'showWhen' => 'by']),
        ]);
        $definition = new PanelFormDefinition($form, 't', $this->resolver([]));

        $groups = $definition->getFieldGroups($form);
        $this->assertInstanceOf(ResolvedFormSection::class, $groups[1]);
        $this->assertFalse($groups[1]->isConditional());
        $this->assertStringContainsString('needs both', $definition->validate()[0]);
    }

    public function testSubmissionColumnsMapEachQuestionToItsRawLabelAndKey(): void
    {
        $form = $this->formPage([
            $this->sectionInline([
                $this->blockData('form-textbox', ['label' => 'Fish & chips?', 'name' => 'fish']),
                $this->blockData('form-info', ['text' => 'Some help']),
                $this->blockData('form-radio-group', ['label' => 'Pick', 'options' => "A\nB"], 'abcdef12-0000-4000-8000-000000000000'),
            ]),
        ]);

        $definition = new PanelFormDefinition($form, 't', $this->resolver([]));

        $this->assertSame([
            'fish'       => ['label' => 'Fish & chips?', 'column' => 'fish'],
            'f_abcdef12' => ['label' => 'Pick', 'column' => 'f_abcdef12'],
        ], $definition->getSubmissionColumns());
    }

    public function testReportAsOverridesTheExportColumn(): void
    {
        $form = $this->formPage([
            $this->sectionInline([
                $this->blockData('form-textbox', ['label' => 'Your view', 'name' => 'view', 'reportAs' => ' Overall view ']),
                $this->blockData('form-textbox', ['label' => 'Other', 'name' => 'other', 'reportAs' => 'first, second']),
            ]),
        ]);

        $columns = (new PanelFormDefinition($form, 't', $this->resolver([])))->getSubmissionColumns();

        $this->assertSame('Overall view', $columns['view']['column']);
        $this->assertSame('first', $columns['other']['column']);
    }

    public function testSubmissionColumnsLeaveOutFieldsDroppedFromTheForm(): void
    {
        $form = $this->formPage([
            $this->sectionInline([
                $this->blockData('form-textbox', ['label' => 'First', 'name' => 'dup']),
                $this->blockData('form-textbox', ['label' => 'Second', 'name' => 'dup']),
                $this->blockData('form-textbox', ['label' => 'Bad', 'name' => '9bad']),
            ]),
        ]);

        $columns = (new PanelFormDefinition($form, 't', $this->resolver([])))->getSubmissionColumns();

        $this->assertSame(['dup' => ['label' => 'First', 'column' => 'dup']], $columns);
    }

    /**
     * @param array<FormFieldSpec|FormSection> $groups
     */
    private function handWritten(array $groups): BaseFormDefinition
    {
        return new class ($groups) extends BaseFormDefinition {
            /** @param array<FormFieldSpec|FormSection> $groups */
            public function __construct(private readonly array $groups)
            {
            }

            public function getFormType(): string
            {
                return 'hand_written';
            }

            /** @return array<FormFieldSpec|FormSection> */
            protected function defineForm(): array
            {
                return $this->groups;
            }
        };
    }
}
