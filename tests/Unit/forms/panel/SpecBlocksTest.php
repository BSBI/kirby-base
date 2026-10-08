<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\forms\panel;

use BSBI\WebBase\forms\FormFieldSpec;
use BSBI\WebBase\forms\panel\PanelFieldReader;
use BSBI\WebBase\forms\panel\SpecBlocks;
use BSBI\WebBase\forms\ResolvedFormField;
use BSBI\WebBase\Testing\KirbyTestEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SpecBlocks: hand-written fields turned into panel field blocks,
 * which read back (PanelFieldReader) as the same field.
 */
final class SpecBlocksTest extends TestCase
{
    use PanelFormFixtures;

    public static function setUpBeforeClass(): void
    {
        KirbyTestEnvironment::boot('kirby-base-spec-blocks-' . uniqid());
    }

    /**
     * @return array<string, array{FormFieldSpec}>
     */
    public static function specs(): array
    {
        return [
            'textbox'          => [FormFieldSpec::textbox('location', 'Where was it?')->required()],
            'email textbox'    => [FormFieldSpec::textbox('email', 'Email', 'email')->help('We reply here')],
            'date'             => [FormFieldSpec::date('event_date', 'When was it?')->required()],
            'textarea'         => [FormFieldSpec::textarea('useful', 'What was useful?')],
            'checkbox group'   => [FormFieldSpec::checkboxGroup('topics', 'Topics', ['Grasses', 'Sedges & rushes'])->required()],
            'radio group'      => [FormFieldSpec::radioGroup('attended', 'Attended before?', ['Yes', 'No'])],
            'select'           => [FormFieldSpec::select('age', 'Age group', ['Under 18', '18-24', '25+'])],
            'likert default'   => [FormFieldSpec::likert('confident', 'I feel confident')],
            'likert 0 to 7'    => [FormFieldSpec::likert('knowledge', 'Your knowledge', 'None', 'Some', 'Expert', 0, 7)],
            'rating matrix'    => [FormFieldSpec::ratingMatrix('rating', 'Rate the event', ['Venue', 'Leader'], ['Poor', 'Good'])],
        ];
    }

    #[DataProvider('specs')]
    public function testAFieldReadsBackAsTheSameField(FormFieldSpec $spec): void
    {
        $original = $spec->resolve([]);

        $block = $this->block(SpecBlocks::block($original, 'seed'));
        $read = (new PanelFieldReader())->read($block);

        $this->assertNotNull($read);
        $this->assertSame($original->name, $read->key);
        $this->assertEquals($this->escaped($original), $read->spec->resolve([]));
    }

    public function testOverridesAreCarriedBecauseTheResolvedFieldIsConverted(): void
    {
        $spec = FormFieldSpec::radioGroup('attended', 'Attended before?', ['Yes', 'No'])->overridable('label');
        $resolved = $spec->resolve(['label' => 'Have you been before?']);

        $this->assertSame('Have you been before?', SpecBlocks::block($resolved, 'seed')['content']['label']);
    }

    public function testMarkupInLabelsIsDroppedKeepingTheText(): void
    {
        $field = new ResolvedFormField(type: 'textarea', name: 'actions', label: 'What will you do after <mark>this event</mark>?');

        $this->assertSame('What will you do after this event?', SpecBlocks::block($field, 'seed')['content']['label']);
    }

    public function testSiteBlocksBecomeAnInfoBlockOfTheirText(): void
    {
        $field = new ResolvedFormField(
            type: FormFieldSpec::TYPE_SITE_BLOCKS,
            name: 'edi_intro',
            label: '',
            content: '<h2>About these questions</h2><p>They are <strong>optional</strong> &amp; anonymous.</p><ul><li>One</li><li>Two</li></ul>',
        );

        $block = SpecBlocks::block($field, 'seed');

        $this->assertSame('form-info', $block['type']);
        $this->assertSame("## About these questions\n\nThey are optional & anonymous.\n\n- One\n- Two", $block['content']['text']);
    }

    public function testBlockIdsAreStableForTheSameSeedAndKey(): void
    {
        $field = FormFieldSpec::textarea('useful', 'Useful?')->resolve([]);

        $this->assertSame(SpecBlocks::block($field, 'a')['id'], SpecBlocks::block($field, 'a')['id']);
        $this->assertNotSame(SpecBlocks::block($field, 'a')['id'], SpecBlocks::block($field, 'b')['id']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', SpecBlocks::block($field, 'a')['id']);
    }

    /**
     * The reader escapes editor text; hand-written text was trusted. The
     * comparison applies the same escaping to the original.
     */
    private function escaped(ResolvedFormField $field): ResolvedFormField
    {
        $e = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        return new ResolvedFormField(
            type: $field->type,
            name: $field->name,
            label: $e($field->label),
            required: $field->required,
            inputType: $field->inputType,
            help: $field->help !== '' ? $e($field->help) : '',
            options: array_map($e, $field->options),
            leftLabel: $e($field->leftLabel),
            middleLabel: $e($field->middleLabel),
            rightLabel: $e($field->rightLabel),
            scaleMin: $field->scaleMin,
            scaleMax: $field->scaleMax,
            content: $field->content,
            rows: $field->rows,
            columns: $field->columns,
            safeMarkdown: $field->safeMarkdown,
        );
    }
}
