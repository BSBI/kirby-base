<?php

declare(strict_types=1);

namespace BSBI\WebBase\forms;

/**
 * An immutable value object representing a form field with all properties
 * fully resolved (defaults merged with any panel overrides).
 *
 * Passed from BaseFormDefinition to template snippets.
 */
readonly class ResolvedFormField
{
    /**
     * @param string        $type        One of: textbox, textarea, checkbox-group, radio-group, likert, select, info, rating-matrix
     * @param string        $name        The HTML input name attribute and POST key
     * @param string        $label       The human-readable question label
     * @param bool          $required    Whether the field is required
     * @param string        $inputType   For textbox: the HTML input type (text, email, date, …)
     * @param string        $help        Optional help text shown beneath the field
     * @param string[]      $options     For checkbox-group, radio-group, select: the option values
     * @param string        $leftLabel   For likert: left-end label
     * @param string        $middleLabel For likert: centre label
     * @param string        $rightLabel  For likert: right-end label
     * @param int           $scaleMin    For likert: minimum scale value
     * @param int           $scaleMax    For likert: maximum scale value
     * @param string        $content     For info: markdown content to display (not a form input)
     * @param string[]      $rows        For rating-matrix: row labels (items to rate)
     * @param string[]      $columns     For rating-matrix: column labels (scale options)
     * @param bool          $safeMarkdown For info: render content in markdown safe mode (editor-authored)
     * @param string|list<string> $value The answer shown in the field: filled in by the definition
     *                                   (FormFieldSpec::value()), or what was posted when a refused
     *                                   submission is shown again (withSubmitted()). A list for a
     *                                   checkbox group, a string otherwise.
     */
    public function __construct(
        public readonly string $type,
        public readonly string $name,
        public readonly string $label,
        public readonly bool $required = false,
        public readonly string $inputType = 'text',
        public readonly string $help = '',
        public readonly array $options = [],
        public readonly string $leftLabel = 'Strongly disagree',
        public readonly string $middleLabel = '',
        public readonly string $rightLabel = 'Strongly agree',
        public readonly int $scaleMin = 1,
        public readonly int $scaleMax = 5,
        public readonly string $content = '',
        public readonly array $rows = [],
        public readonly array $columns = [],
        public readonly bool $safeMarkdown = false,
        public readonly string|array $value = '',
    ) {
    }

    /** Field types that take an answer from a single posted string. */
    private const array STRING_ANSWER_TYPES = ['textbox', 'textarea', 'radio-group', 'select', 'likert'];

    /**
     * A copy of this field showing $value.
     *
     * @param string|list<string> $value
     */
    public function withValue(string|array $value): self
    {
        return new self(
            type:         $this->type,
            name:         $this->name,
            label:        $this->label,
            required:     $this->required,
            inputType:    $this->inputType,
            help:         $this->help,
            options:      $this->options,
            leftLabel:    $this->leftLabel,
            middleLabel:  $this->middleLabel,
            rightLabel:   $this->rightLabel,
            scaleMin:     $this->scaleMin,
            scaleMax:     $this->scaleMax,
            content:      $this->content,
            rows:         $this->rows,
            columns:      $this->columns,
            safeMarkdown: $this->safeMarkdown,
            value:        $value,
        );
    }

    /**
     * A copy showing what was posted for this field, so a form shown again after a
     * refused submission keeps its answers. A question not posted (left unanswered,
     * or in a hidden section) comes back empty, not with its filled-in value. Fields
     * that take no answer (info, site blocks) and rating matrices are returned as they are.
     *
     * @param array<mixed> $postData
     */
    public function withSubmitted(array $postData): self
    {
        $posted = $postData[$this->name] ?? null;
        if (in_array($this->type, self::STRING_ANSWER_TYPES, true)) {
            return $this->withValue(is_string($posted) || is_int($posted) ? (string) $posted : '');
        }
        if ($this->type === 'checkbox-group') {
            $values = is_array($posted) ? $posted : ($posted === null ? [] : [$posted]);
            return $this->withValue(array_values(array_map(
                static fn (bool|float|int|string $v): string => (string) $v,
                array_filter($values, 'is_scalar')
            )));
        }
        return $this;
    }

    /**
     * Whether an option of a radio group, checkbox group, select or likert scale is
     * the field's answer (or one of them).
     */
    public function isSelected(string|int $option): bool
    {
        // A definition may give options as numbers; answers are always strings.
        $option = (string) $option;
        return is_array($this->value)
            ? in_array($option, $this->value, true)
            : $this->value !== '' && $this->value === $option;
    }

    /**
     * The value as text, for a textbox, textarea or select.
     */
    private function valueAsString(): string
    {
        return is_array($this->value) ? '' : $this->value;
    }

    /**
     * Returns an info field's content rendered from markdown. Panel-authored
     * content (safeMarkdown) is rendered in Parsedown's safe mode, which escapes
     * raw HTML and neutralises javascript:/data: link targets; developer-authored
     * content may use raw HTML.
     */
    public function contentHtml(): string
    {
        return markdown($this->content, ['safe' => $this->safeMarkdown]);
    }

    /**
     * Returns the arguments array to pass directly to the form/textbox snippet.
     *
     * @return array<string, mixed>
     */
    public function toTextboxArgs(): array
    {
        return [
            'id'       => $this->name,
            'name'     => $this->name,
            'label'    => $this->label,
            'type'     => $this->inputType,
            'required' => $this->required,
            'value'    => $this->valueAsString(),
        ];
    }

    /**
     * Returns the arguments array to pass directly to the form/textarea snippet.
     *
     * @return array<string, mixed>
     */
    public function toTextareaArgs(): array
    {
        return [
            'id'       => $this->name,
            'name'     => $this->name,
            'label'    => $this->label,
            'required' => $this->required,
            'value'    => $this->valueAsString(),
        ];
    }

    /**
     * Returns the arguments array to pass directly to the form/select snippet.
     * Converts the flat options array to the value/display pair format the snippet expects.
     *
     * @return array<string, mixed>
     */
    public function toSelectArgs(): array
    {
        return [
            'id'       => $this->name,
            'name'     => $this->name,
            'label'    => $this->label,
            'required' => $this->required,
            'selectedValue' => $this->valueAsString(),
            'options'  => array_map(
                static fn(string $o): array => ['value' => $o, 'display' => $o],
                $this->options
            ),
        ];
    }

    /**
     * Returns the arguments array to pass directly to the form/likert snippet.
     *
     * @return array<string, mixed>
     */
    public function toLikertArgs(): array
    {
        return [
            'name'        => $this->name,
            'label'       => $this->label,
            'required'    => $this->required,
            'leftLabel'   => $this->leftLabel,
            'middleLabel' => $this->middleLabel,
            'rightLabel'  => $this->rightLabel,
            'scaleMin'    => $this->scaleMin,
            'scaleMax'    => $this->scaleMax,
            'value'       => $this->valueAsString(),
        ];
    }

    /**
     * Returns the arguments array to pass directly to the form/rating-matrix snippet.
     *
     * @return array<string, mixed>
     */
    public function toRatingMatrixArgs(): array
    {
        return [
            'name'     => $this->name,
            'label'    => $this->label,
            'required' => $this->required,
            'help'     => $this->help,
            'rows'     => $this->rows,
            'columns'  => $this->columns,
        ];
    }
}
