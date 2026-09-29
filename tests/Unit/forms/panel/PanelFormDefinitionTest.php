<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\forms\panel;

use BSBI\WebBase\forms\BaseFormDefinition;
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
