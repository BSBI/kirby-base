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
 * text is HTML-escaped here. Rating-matrix rows and columns are left raw because
 * that snippet escapes them itself.
 */
final class PanelFieldReader
{
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

    /** HTML input types an editor may pick for a text input. */
    public const INPUT_TYPES = ['text', 'email', 'tel', 'number', 'date', 'url'];

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
        $rawLabel = $this->text($block, 'label');
        $label    = $this->escape($rawLabel);
        $options  = $this->lines($block, 'options');

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
                $this->escape($this->text($block, 'leftLabel', 'Strongly disagree')),
                $this->escape($this->text($block, 'middleLabel')),
                $this->escape($this->text($block, 'rightLabel', 'Strongly agree')),
            ),
            FormFieldSpec::TYPE_RATING_MATRIX  => FormFieldSpec::ratingMatrix(
                $key,
                $label,
                $this->lines($block, 'rows'),
                $this->lines($block, 'columns'),
            ),
            default                            => FormFieldSpec::info($key, $this->text($block, 'text')),
        };

        if ($type !== FormFieldSpec::TYPE_INFO) {
            $help = $this->text($block, 'help');
            if ($help !== '') {
                $spec->help($this->escape($help));
            }
            if (PanelContent::field($block->content(), 'required')->toBool()) {
                $spec->required();
            }
        }

        $isChoice = in_array($type, [
            FormFieldSpec::TYPE_CHECKBOX_GROUP,
            FormFieldSpec::TYPE_RADIO_GROUP,
            FormFieldSpec::TYPE_SELECT,
        ], true);

        return new PanelField($spec, $key, $type, $isChoice ? $options : [], $rawLabel);
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
     * Returns the non-blank, trimmed lines of a textarea value.
     *
     * @param Block  $block
     * @param string $field
     * @return string[]
     */
    private function lines(Block $block, string $field): array
    {
        $lines = array_map('trim', explode("\n", PanelContent::text($block->content(), $field)));
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
