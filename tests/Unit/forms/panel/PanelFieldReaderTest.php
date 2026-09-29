<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\forms\panel;

use BSBI\WebBase\forms\FormFieldSpec;
use BSBI\WebBase\forms\panel\PanelFieldReader;
use BSBI\WebBase\Testing\KirbyTestEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PanelFieldReader: form-* blocks become FormFieldSpec objects that
 * resolve to the same fields a hand-written definition would give.
 */
final class PanelFieldReaderTest extends TestCase
{
    use PanelFormFixtures;

    public static function setUpBeforeClass(): void
    {
        KirbyTestEnvironment::boot('kirby-base-panel-field-reader-' . uniqid());
    }

    // ── Keys ────────────────────────────────────────────────────────────────

    public function testExplicitNameIsTheKey(): void
    {
        $block = $this->block($this->blockData('form-textbox', ['label' => 'Email', 'name' => 'email']));
        $this->assertSame('email', PanelFieldReader::keyFor($block));
    }

    public function testBlankNameGivesAKeyFromTheBlockId(): void
    {
        $block = $this->block($this->blockData(
            'form-textbox',
            ['label' => 'Email', 'name' => '  '],
            '3f2a9c1e-7b4d-4e0a-9c2b-1a2b3c4d5e6f'
        ));
        $this->assertSame('f_3f2a9c1e', PanelFieldReader::keyFor($block));
    }

    public function testGeneratedKeyIsStableAcrossLabelEdits(): void
    {
        $id = '0badc0de-1111-4222-8333-444455556666';
        $before = $this->block($this->blockData('form-textbox', ['label' => 'Email'], $id));
        $after  = $this->block($this->blockData('form-textbox', ['label' => 'Your email address'], $id));

        $this->assertSame(PanelFieldReader::keyFor($before), PanelFieldReader::keyFor($after));
    }

    #[DataProvider('validNames')]
    public function testValidNames(string $name): void
    {
        $this->assertTrue(PanelFieldReader::isValidKey($name));
    }

    /** @return array<string, array{string}> */
    public static function validNames(): array
    {
        return [
            'snake'   => ['first_name'],
            'hyphen'  => ['skill-level'],
            'digits'  => ['resolution_1'],
            'capital' => ['Knowledge_Start'],
        ];
    }

    #[DataProvider('invalidNames')]
    public function testInvalidNames(string $name): void
    {
        $this->assertFalse(PanelFieldReader::isValidKey($name));
    }

    /** @return array<string, array{string}> */
    public static function invalidNames(): array
    {
        return [
            'space'         => ['first name'],
            'dot'           => ['first.name'],
            'leading digit' => ['1st'],
            'bracket'       => ['name[]'],
            'reserved csrf' => ['csrf'],
            'too long'      => [str_repeat('a', 65)],
        ];
    }

    // ── Types ───────────────────────────────────────────────────────────────

    public function testTextbox(): void
    {
        $field = $this->read('form-textbox', [
            'label' => 'Email', 'name' => 'email', 'required' => 'true',
            'help' => 'We reply here', 'inputType' => 'email',
        ]);

        $expected = FormFieldSpec::textbox('email', 'Email', 'email')->required()->help('We reply here');
        $this->assertEquals($expected->resolve([]), $field->spec->resolve([]));
        $this->assertSame([], $field->options);
    }

    public function testTextboxDefaultsToTextAndIgnoresUnknownInputTypes(): void
    {
        $field = $this->read('form-textbox', ['label' => 'Name', 'name' => 'n', 'inputType' => 'password']);
        $this->assertSame('text', $field->spec->resolve([])->inputType);
    }

    public function testTextarea(): void
    {
        $field = $this->read('form-textarea', ['label' => 'Comments', 'name' => 'comments']);
        $this->assertEquals(
            FormFieldSpec::textarea('comments', 'Comments')->resolve([]),
            $field->spec->resolve([])
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function choiceTypes(): array
    {
        return [
            'radio'    => ['form-radio-group', FormFieldSpec::TYPE_RADIO_GROUP],
            'checkbox' => ['form-checkbox-group', FormFieldSpec::TYPE_CHECKBOX_GROUP],
            'select'   => ['form-select', FormFieldSpec::TYPE_SELECT],
        ];
    }

    #[DataProvider('choiceTypes')]
    public function testChoiceFieldsReadOptionsOnePerLine(string $blockType, string $specType): void
    {
        $field = $this->read($blockType, [
            'label' => 'Pick', 'name' => 'pick', 'options' => "Yes\n\n  No  \nMaybe\n",
        ]);

        $resolved = $field->spec->resolve([]);
        $this->assertSame($specType, $resolved->type);
        $this->assertSame(['Yes', 'No', 'Maybe'], $resolved->options);
        $this->assertSame(['Yes', 'No', 'Maybe'], $field->options);
    }

    public function testLikertUsesSpecDefaultsForBlankEndLabels(): void
    {
        $field = $this->read('form-likert', ['label' => 'Rate it', 'name' => 'rate']);
        $this->assertEquals(FormFieldSpec::likert('rate', 'Rate it')->resolve([]), $field->spec->resolve([]));
    }

    public function testLikertEndLabels(): void
    {
        $field = $this->read('form-likert', [
            'label' => 'Rate it', 'name' => 'rate',
            'leftLabel' => 'Poor', 'middleLabel' => 'OK', 'rightLabel' => 'Great',
        ]);
        $this->assertEquals(
            FormFieldSpec::likert('rate', 'Rate it', 'Poor', 'OK', 'Great')->resolve([]),
            $field->spec->resolve([])
        );
    }

    public function testRatingMatrix(): void
    {
        $field = $this->read('form-rating-matrix', [
            'label' => 'Rate each', 'name' => 'grid',
            'rows' => "Venue\nSpeakers", 'columns' => "Poor\nGood",
        ]);
        $this->assertEquals(
            FormFieldSpec::ratingMatrix('grid', 'Rate each', ['Venue', 'Speakers'], ['Poor', 'Good'])->resolve([]),
            $field->spec->resolve([])
        );
    }

    public function testInfoIsDisplayOnly(): void
    {
        $field = $this->read('form-info', ['name' => 'intro', 'text' => 'Please **read** this & that']);
        $this->assertEquals(
            FormFieldSpec::info('intro', 'Please **read** this &amp; that')->resolve([]),
            $field->spec->resolve([])
        );
        $this->assertFalse($field->isSubmittable());
    }

    public function testUnknownBlockTypeGivesNull(): void
    {
        $block = $this->block($this->blockData('text', ['text' => 'hello']));
        $this->assertNull((new PanelFieldReader())->read($block));
    }

    // ── Escaping ────────────────────────────────────────────────────────────

    public function testEditorTextIsEscapedForTheUnescapedFieldSnippets(): void
    {
        $field = $this->read('form-radio-group', [
            'label' => 'Tom & <b>Jerry</b>', 'name' => 'pick',
            'help' => '<script>x</script>', 'options' => "A & B\n<i>C</i>",
        ]);

        $resolved = $field->spec->resolve([]);
        $this->assertSame('Tom &amp; &lt;b&gt;Jerry&lt;/b&gt;', $resolved->label);
        $this->assertSame('&lt;script&gt;x&lt;/script&gt;', $resolved->help);
        $this->assertSame(['A &amp; B', '&lt;i&gt;C&lt;/i&gt;'], $resolved->options);
        // Raw options are kept for condition checks, which compare submitted values.
        $this->assertSame(['A & B', '<i>C</i>'], $field->options);
    }

    public function testRatingMatrixRowsAndColumnsAreLeftRawBecauseItsSnippetEscapes(): void
    {
        $field = $this->read('form-rating-matrix', [
            'label' => 'A & B', 'name' => 'grid', 'rows' => 'R & D', 'columns' => 'Good & bad',
        ]);
        $resolved = $field->spec->resolve([]);
        $this->assertSame('A &amp; B', $resolved->label);
        $this->assertSame(['R & D'], $resolved->rows);
        $this->assertSame(['Good & bad'], $resolved->columns);
    }

    public function testInfoTextKeepsMarkdownButNotRawHtml(): void
    {
        $field = $this->read('form-info', ['text' => "Please **read** <img src=x onerror=alert(1)>"]);
        $html = markdown($field->spec->resolve([])->content);

        $this->assertStringContainsString('<strong>read</strong>', $html);
        $this->assertStringNotContainsString('<img', $html);
    }

    public function testLikertEndLabelsAreEscaped(): void
    {
        $field = $this->read('form-likert', ['label' => 'Q', 'name' => 'q', 'leftLabel' => '<b>no</b>']);
        $this->assertSame('&lt;b&gt;no&lt;/b&gt;', $field->spec->resolve([])->leftLabel);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $content
     */
    private function read(string $type, array $content): \BSBI\WebBase\forms\panel\PanelField
    {
        $field = (new PanelFieldReader())->read($this->block($this->blockData($type, $content)));
        $this->assertNotNull($field);
        return $field;
    }
}
