<?php

declare(strict_types=1);

namespace BSBI\WebBase\forms\panel;

use BSBI\WebBase\forms\FormFieldSpec;
use Kirby\Cms\Block;

/**
 * Turns one `form-*` panel block into a FormFieldSpec.
 *
 * Keys: the block's `name` when an editor (or a migration) has set one,
 * otherwise `f_` plus the first 8 hex digits of the block id. Block ids never
 * change once a block exists, so a generated key survives label edits and
 * reordering — submissions and CSV columns stay continuous.
 *
 * Escaping: the field snippets print labels, help, options and Likert end
 * labels unescaped (they were written for developer-authored text), so editor
 * text is HTML-escaped here. Info text is left raw and flagged for markdown safe
 * mode instead (escaping first would break links containing "&" and quotes, and
 * would not stop javascript: links). Rating-matrix rows and columns are left raw
 * because that snippet escapes them.
 *
 * Adjustments (a form's "Adjust questions on this form" on a library section)
 * replace a question's label, options, rating-grid rows and columns, scale
 * labels or help text, by key, before reading; a blank adjustment keeps the
 * block's own. Each only applies to the question types that have it.
 */
final class PanelFieldReader
{
    /**
     * @param array<string, array<string, string>> $adjustments By question key: ADJUSTABLE name => text
     */
    public function __construct(private array $adjustments = [])
    {
    }

    /** @var array<string, string> Block type => FormFieldSpec type */
    public const BLOCK_TYPES = [
        'form-textbox'        => FormFieldSpec::TYPE_TEXTBOX,
        'form-textarea'       => FormFieldSpec::TYPE_TEXTAREA,
        'form-checkbox-group' => FormFieldSpec::TYPE_CHECKBOX_GROUP,
        'form-radio-group'    => FormFieldSpec::TYPE_RADIO_GROUP,
        'form-select'         => FormFieldSpec::TYPE_SELECT,
        'form-likert'         => FormFieldSpec::TYPE_LIKERT,
        'form-rating-matrix'  => FormFieldSpec::TYPE_RATING_MATRIX,
        'form-info'           => FormFieldSpec::TYPE_INFO,
    ];

    /**
     * What an adjustment may replace: the block field names, each blank to
     * keep the block's own. Rows, columns and options are one per line.
     */
    public const ADJUSTABLE = ['label', 'help', 'options', 'rows', 'columns', 'leftLabel', 'middleLabel', 'rightLabel'];

    /** HTML input types an editor may pick for a text input. */
    public const INPUT_TYPES = ['text', 'email', 'tel', 'number', 'date', 'url'];

    /** The highest Likert scale point an editor may set. */
    public const MAX_SCALE = 10;

    /** POST keys the form machinery uses itself. */
    private const RESERVED_KEYS = ['csrf', 'submit'];

    /**
     * Returns the POST key for a field block.
     *
     * @param Block $block A form-* block
     */
    public static function keyFor(Block $block): string
    {
        $name = PanelContent::text($block->content(), 'name');
        if ($name !== '') {
            return $name;
        }

        $hex = (string) preg_replace('/[^a-f0-9]/', '', strtolower($block->id()));
        return 'f_' . substr($hex, 0, 8);
    }

    /**
     * Returns true if $key is safe as a POST key and HTML id: starts with a
     * letter, then letters, digits, underscores or hyphens (PHP rewrites dots
     * and spaces in POST keys), at most 64 characters, and not reserved.
     *
     * @param string $key Candidate key
     */
    public static function isValidKey(string $key): bool
    {
        return preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $key) === 1
            && !in_array(strtolower($key), self::RESERVED_KEYS, true);
    }

    /**
     * Reads a block into a PanelField, or returns null if the block is not a
     * form field type. The key is not validated here; see isValidKey().
     *
     * @param Block $block The block to read
     */
    public function read(Block $block): ?PanelField
    {
        $type = self::BLOCK_TYPES[$block->type()] ?? null;
        if ($type === null) {
            return null;
        }

        $key      = self::keyFor($block);
        $adjusted = $type === FormFieldSpec::TYPE_INFO ? [] : ($this->adjustments[$key] ?? []);
        // Display-only text has no label: carry its text, for panel summaries.
        $rawLabel = $type === FormFieldSpec::TYPE_INFO ? $this->text($block, 'text') : $this->text($block, 'label');
        if (($adjusted['label'] ?? '') !== '') {
            $rawLabel = $adjusted['label'];
        }
        $label    = $this->escape($rawLabel);
        // The block's text, or this form's adjustment of it when there is one.
        $text     = fn(string $name, string $default = ''): string
            => ($adjusted[$name] ?? '') !== '' ? $adjusted[$name] : $this->text($block, $name, $default);
        $lines    = fn(string $name): array => self::linesOf($text($name));
        $options  = $lines('options');

        $escapedOptions = $this->escapeAll($options);

        $spec = match ($type) {
            FormFieldSpec::TYPE_TEXTBOX        => FormFieldSpec::textbox($key, $label, $this->inputType($block)),
            FormFieldSpec::TYPE_TEXTAREA       => FormFieldSpec::textarea($key, $label),
            FormFieldSpec::TYPE_CHECKBOX_GROUP => FormFieldSpec::checkboxGroup($key, $label, $escapedOptions),
            FormFieldSpec::TYPE_RADIO_GROUP    => FormFieldSpec::radioGroup($key, $label, $escapedOptions),
            FormFieldSpec::TYPE_SELECT         => FormFieldSpec::select($key, $label, $escapedOptions),
            FormFieldSpec::TYPE_LIKERT         => FormFieldSpec::likert(
                $key,
                $label,
                $this->escape($text('leftLabel', 'Strongly disagree')),
                $this->escape($text('middleLabel')),
                $this->escape($text('rightLabel', 'Strongly agree')),
                ...self::scale($block),
            ),
            FormFieldSpec::TYPE_RATING_MATRIX  => FormFieldSpec::ratingMatrix(
                $key,
                $label,
                $lines('rows'),
                $lines('columns'),
            ),
            default                            => FormFieldSpec::info($key, $this->text($block, 'text'), true),
        };

        $help = $type !== FormFieldSpec::TYPE_INFO ? $text('help') : '';
        if ($type !== FormFieldSpec::TYPE_INFO) {
            if ($help !== '') {
                $spec->help($this->escape($help));
            }
            if (PanelContent::field($block->content(), 'required')->toBool()) {
                $spec->required();
            }
        }

        $isChoice = in_array($type, PanelField::CHOICE_TYPES, true);

        return new PanelField(
            $spec,
            $key,
            $type,
            $isChoice ? $options : [],
            $rawLabel,
            $type !== FormFieldSpec::TYPE_INFO ? $this->reportAs($block) : '',
            $this->fillFrom($block, $type),
            self::wording($type, $rawLabel, $help, $options, $lines('rows'), $lines('columns'), [
                $text('leftLabel', 'Strongly disagree'),
                $text('middleLabel'),
                $text('rightLabel', 'Strongly agree'),
            ])
        );
    }

    /**
     * Returns a question's wording as read, raw, by ADJUSTABLE name: only the
     * ones its type has (label and help for every question; options for a
     * choice question; rows and columns for a rating grid; the three labels
     * for a scale). Lists are one per line, as an editor types them.
     *
     * @param string                                 $type    A FormFieldSpec::TYPE_* constant
     * @param list<string>                           $options
     * @param list<string>                           $rows
     * @param list<string>                           $columns
     * @param array{0: string, 1: string, 2: string} $labels  A scale's left, middle and right labels
     * @return array<string, string>
     */
    private static function wording(
        string $type,
        string $label,
        string $help,
        array $options,
        array $rows,
        array $columns,
        array $labels
    ): array {
        if ($type === FormFieldSpec::TYPE_INFO) {
            return [];
        }
        $wording = ['label' => $label, 'help' => $help];
        return $wording + match ($type) {
            FormFieldSpec::TYPE_CHECKBOX_GROUP,
            FormFieldSpec::TYPE_RADIO_GROUP,
            FormFieldSpec::TYPE_SELECT        => ['options' => implode("\n", $options)],
            FormFieldSpec::TYPE_RATING_MATRIX => ['rows' => implode("\n", $rows), 'columns' => implode("\n", $columns)],
            FormFieldSpec::TYPE_LIKERT        => [
                'leftLabel'   => $labels[0],
                'middleLabel' => $labels[1],
                'rightLabel'  => $labels[2],
            ],
            default                           => [],
        };
    }

    /**
     * Returns the block's "Report as" export column: the first entry of the
     * tags field, trimmed, or blank when none is set.
     *
     * @param Block $block
     */
    /**
     * Returns a Likert block's scale as named arguments: its own when usable
     * (whole numbers, 0 to 10, the first below the second), else 1 to 5.
     *
     * @param Block $block
     * @return array{scaleMin: int, scaleMax: int}
     */
    public static function scale(Block $block): array
    {
        $min = PanelContent::text($block->content(), 'scaleMin');
        $max = PanelContent::text($block->content(), 'scaleMax');
        $isWhole = static fn(string $value): bool => preg_match('/^\d{1,2}$/', $value) === 1;

        if ($isWhole($min) && $isWhole($max) && (int) $min < (int) $max && (int) $max <= self::MAX_SCALE) {
            return ['scaleMin' => (int) $min, 'scaleMax' => (int) $max];
        }
        return ['scaleMin' => 1, 'scaleMax' => 5];
    }

    private function reportAs(Block $block): string
    {
        $first = explode(',', PanelContent::text($block->content(), 'reportAs'))[0];
        return trim($first);
    }

    /**
     * Returns what an "about" link fills the answer with: 'date' on a text or
     * date question; 'title' or a detail (a field name, e.g. 'ledBy') on a
     * text question or long answer; else blank.
     */
    private function fillFrom(Block $block, string $type): string
    {
        $fillFrom = $this->text($block, 'fillFrom');
        $inputType = $type === FormFieldSpec::TYPE_TEXTBOX ? $this->inputType($block) : '';
        $isText = $type === FormFieldSpec::TYPE_TEXTAREA || $inputType === 'text';
        return match (true) {
            $fillFrom === 'date' => in_array($inputType, ['text', 'date'], true) ? 'date' : '',
            $isText && preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $fillFrom) === 1 => $fillFrom,
            default => '',
        };
    }

    /**
     * Returns a trimmed text value from the block, or $default when blank.
     *
     * @param Block  $block
     * @param string $field
     * @param string $default
     */
    private function text(Block $block, string $field, string $default = ''): string
    {
        $value = PanelContent::text($block->content(), $field);
        return $value !== '' ? $value : $default;
    }

    /**
     * Returns the non-blank, trimmed lines of a text.
     *
     * @return list<string>
     */
    private static function linesOf(string $text): array
    {
        $lines = array_map('trim', explode("\n", $text));
        return array_values(array_filter($lines, static fn(string $line): bool => $line !== ''));
    }

    /**
     * Returns the block's input type if it is one editors may pick, else 'text'.
     *
     * @param Block $block
     */
    private function inputType(Block $block): string
    {
        $inputType = $this->text($block, 'inputType', 'text');
        return in_array($inputType, self::INPUT_TYPES, true) ? $inputType : 'text';
    }

    /**
     * HTML-escapes editor text for the unescaped field snippets.
     *
     * @param string $text
     */
    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    /**
     * HTML-escapes each string in a list.
     *
     * @param string[] $texts
     * @return string[]
     */
    private function escapeAll(array $texts): array
    {
        return array_map($this->escape(...), $texts);
    }
}
