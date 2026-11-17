<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\forms;

use BSBI\WebBase\forms\FormFieldSpec;
use BSBI\WebBase\forms\ResolvedFormField;
use BSBI\WebBase\forms\ResolvedFormSection;
use PHPUnit\Framework\TestCase;

/**
 * Field values on definition forms: a definition can fill a field in
 * (FormFieldSpec::value()), and a form shown again after a refused submission
 * keeps what was answered (withSubmitted()).
 */
final class FormFieldValuesTest extends TestCase
{
    public function testADefinitionCanFillFieldsIn(): void
    {
        $textarea = FormFieldSpec::textarea('notes', 'Notes')->value('Current wording')->resolve([]);
        $textbox = FormFieldSpec::textbox('name', 'Name')->value('Ann')->resolve([]);
        $radio = FormFieldSpec::radioGroup('ok', 'OK?', ['Yes', 'No'])->value('No')->resolve([]);
        $checks = FormFieldSpec::checkboxGroup('help', 'Help', ['A', 'B', 'C'])->value(['A', 'C'])->resolve([]);

        $this->assertSame('Current wording', $textarea->toTextareaArgs()['value']);
        $this->assertSame('Ann', $textbox->toTextboxArgs()['value']);
        $this->assertTrue($radio->isSelected('No'));
        $this->assertFalse($radio->isSelected('Yes'));
        $this->assertTrue($checks->isSelected('A'));
        $this->assertFalse($checks->isSelected('B'));
        $this->assertTrue($checks->isSelected('C'));
    }

    public function testFieldsHaveNoValueUnlessGivenOne(): void
    {
        $field = FormFieldSpec::textarea('notes', 'Notes')->resolve([]);

        $this->assertSame('', $field->toTextareaArgs()['value']);
        $this->assertFalse(FormFieldSpec::radioGroup('ok', 'OK?', ['Yes'])->resolve([])->isSelected('Yes'));
    }

    public function testARefusedSubmissionKeepsWhatWasAnswered(): void
    {
        $post = [
            'notes' => 'Typed text',
            'ok' => 'Yes',
            'help' => ['B'],
            'scale' => '4',
            'pick' => 'Two',
        ];

        $textarea = FormFieldSpec::textarea('notes', 'Notes')->value('Prefilled')->resolve([])->withSubmitted($post);
        $radio = FormFieldSpec::radioGroup('ok', 'OK?', ['Yes', 'No'])->value('No')->resolve([])->withSubmitted($post);
        $checks = FormFieldSpec::checkboxGroup('help', 'Help', ['A', 'B'])->value(['A'])->resolve([])->withSubmitted($post);
        $likert = FormFieldSpec::likert('scale', 'Scale')->resolve([])->withSubmitted($post);
        $select = FormFieldSpec::select('pick', 'Pick', ['One', 'Two'])->resolve([])->withSubmitted($post);

        $this->assertSame('Typed text', $textarea->toTextareaArgs()['value'], 'what was typed beats the prefill');
        $this->assertTrue($radio->isSelected('Yes'));
        $this->assertFalse($radio->isSelected('No'));
        $this->assertTrue($checks->isSelected('B'));
        $this->assertFalse($checks->isSelected('A'), 'unticked on submit stays unticked');
        $this->assertTrue($likert->isSelected('4'));
        $this->assertSame('Two', $select->toSelectArgs()['selectedValue']);
    }

    public function testAQuestionLeftUnansweredIsEmptyAfterASubmission(): void
    {
        $radio = FormFieldSpec::radioGroup('ok', 'OK?', ['Yes', 'No'])->value('No')->resolve([])->withSubmitted([]);
        $checks = FormFieldSpec::checkboxGroup('help', 'Help', ['A'])->value(['A'])->resolve([])->withSubmitted([]);

        $this->assertFalse($radio->isSelected('No'));
        $this->assertFalse($checks->isSelected('A'));
    }

    public function testNonAnswerFieldsAreLeftAlone(): void
    {
        $info = FormFieldSpec::info('intro', 'Some **text**')->resolve([]);

        $this->assertSame($info, $info->withSubmitted(['intro' => 'injected']));
    }

    public function testOddPostedShapesAreIgnoredSafely(): void
    {
        $textarea = FormFieldSpec::textarea('notes', 'Notes')->resolve([])->withSubmitted(['notes' => ['not', 'a', 'string']]);
        $checks = FormFieldSpec::checkboxGroup('help', 'Help', ['A'])->resolve([])->withSubmitted(['help' => 'A']);

        $this->assertSame('', $textarea->toTextareaArgs()['value']);
        $this->assertTrue($checks->isSelected('A'), 'a single posted value counts as one ticked box');
    }

    public function testSectionsKeepTheirFieldsAnswers(): void
    {
        $section = new ResolvedFormSection('s1', 'More', [
            FormFieldSpec::textarea('why', 'Why?')->resolve([]),
        ], 'ok', 'No');

        $kept = $section->withSubmitted(['why' => 'Because']);

        $this->assertSame('Because', $kept->fields[0]->toTextareaArgs()['value']);
        $this->assertSame('ok', $kept->conditionField);
        $this->assertSame('No', $kept->conditionValue);
    }

    public function testValueCarriesThroughWithValue(): void
    {
        $field = new ResolvedFormField(type: 'textarea', name: 'n', label: 'N');

        $this->assertSame('Set', $field->withValue('Set')->toTextareaArgs()['value']);
        $this->assertSame('', $field->toTextareaArgs()['value'], 'immutable');
    }

    public function testAFormPageKeepsEveryAnswerOnARefusedSubmission(): void
    {
        $page = new class {
            use \BSBI\WebBase\traits\FormProperties;
        };
        $page->setFormFields([FormFieldSpec::textbox('name', 'Name')->resolve([])]);
        $page->setFormFieldGroups([
            FormFieldSpec::textbox('name', 'Name')->resolve([]),
            new ResolvedFormSection('s', 'More', [FormFieldSpec::textarea('why', 'Why?')->resolve([])], null, null),
        ]);

        $page->keepSubmittedValues(['name' => 'Ann', 'why' => 'Because']);

        $groups = $page->getResolvedForm()->fieldGroups;
        $this->assertInstanceOf(ResolvedFormField::class, $groups[0]);
        $this->assertSame('Ann', $groups[0]->toTextboxArgs()['value']);
        $this->assertInstanceOf(ResolvedFormSection::class, $groups[1]);
        $this->assertSame('Because', $groups[1]->fields[0]->toTextareaArgs()['value']);
        $this->assertSame('Ann', $page->getFormFields()[0]->toTextboxArgs()['value']);
    }
}
