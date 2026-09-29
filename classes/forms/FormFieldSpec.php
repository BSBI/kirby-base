<?php

declare(strict_types=1);

namespace BSBI\WebBase\forms;

/**
 * Declares a single fixed form field, including which of its properties
 * may be overridden by panel editors.
 *
 * Developers instantiate these via the static factory methods and chain
 * fluent modifiers.  BaseFormDefinition::getFields() resolves each spec
 * against the live page content and returns ResolvedFormField objects.
 *
 * Usage example:
 *
 *   FormFieldSpec::likert('knowledge_start', 'Rate your knowledge at the start')
 *       ->overridable('label')
 *       ->overridable('leftLabel', 'No knowledge at all')
 *       ->overridable('rightLabel', 'Very knowledgeable')
 *       ->required()
 */
class FormFieldSpec
{
    // ── Supported types ────────────────────────────────────────────────────
    public const TYPE_TEXTBOX        = 'textbox';
    public const TYPE_TEXTAREA       = 'textarea';
    public const TYPE_CHECKBOX_GROUP = 'checkbox-group';
    public const TYPE_RADIO_GROUP    = 'radio-group';
    public const TYPE_LIKERT         = 'likert';
    public const TYPE_SELECT         = 'select';
    public const TYPE_INFO           = 'info';
    public const TYPE_RATING_MATRIX  = 'rating-matrix';
    public const TYPE_SITE_BLOCKS    = 'site-blocks';

    /** @var array<string, mixed> Overridable property defaults keyed by property name */
    private array $overridable = [];

    private bool $required = false;

    /** @var string HTML input type for textbox fields */
    private string $inputType = 'text';

    /** @var string[] Default options for checkbox-group / radio-group / select */
    private array $defaultOptions = [];

    private string $defaultHelp        = '';
    private string $defaultContent     = '';
    private bool $safeMarkdown         = false;
    private string $siteFieldName      = '';
    private string $defaultLeftLabel   = 'Strongly disagree';
    private string $defaultMiddleLabel = '';
    private string $defaultRightLabel  = 'Strongly agree';
    private int $defaultScaleMin    = 1;
    private int $defaultScaleMax    = 5;
    /** @var string[] */
    private array $defaultRows    = [];
    /** @var string[] */
    private array $defaultColumns = [];

    // ── Constructor ─────────────────────────────────────────────────────────

    /**
     * @param string $type         One of the TYPE_* constants
     * @param string $name         HTML input name / POST key
     * @param string $defaultLabel The label shown when no panel override exists
     */
    private function __construct(
        private readonly string $type,
        private readonly string $name,
        private string $defaultLabel,
    ) {
    }

    // ── Static factories ────────────────────────────────────────────────────

    /**
     * Creates a single-line text input spec.
     *
     * @param string $name         HTML input name
     * @param string $defaultLabel Default question label
     * @param string $inputType    HTML input type (default: 'text')
     */
    public static function textbox(string $name, string $defaultLabel, string $inputType = 'text'): static
    {
        $spec = new static(self::TYPE_TEXTBOX, $name, $defaultLabel);
        $spec->inputType = $inputType;
        return $spec;
    }

    /**
     * Creates a date input spec (shorthand for textbox with type=date).
     *
     * @param string $name         HTML input name
     * @param string $defaultLabel Default question label
     */
    public static function date(string $name, string $defaultLabel): static
    {
        return static::textbox($name, $defaultLabel, 'date');
    }

    /**
     * Creates a multi-line textarea spec.
     *
     * @param string $name         HTML input name
     * @param string $defaultLabel Default question label
     */
    public static function textarea(string $name, string $defaultLabel): static
    {
        return new static(self::TYPE_TEXTAREA, $name, $defaultLabel);
    }

    /**
     * Creates a checkbox group spec (multiple selections).
     *
     * @param string   $name           HTML input name
     * @param string   $defaultLabel   Default question label
     * @param string[] $defaultOptions Default option values
     */
    public static function checkboxGroup(string $name, string $defaultLabel, array $defaultOptions = []): static
    {
        $spec = new static(self::TYPE_CHECKBOX_GROUP, $name, $defaultLabel);
        $spec->defaultOptions = $defaultOptions;
        return $spec;
    }

    /**
     * Creates a radio group spec (single selection).
     *
     * @param string   $name           HTML input name
     * @param string   $defaultLabel   Default question label
     * @param string[] $defaultOptions Default option values
     */
    public static function radioGroup(string $name, string $defaultLabel, array $defaultOptions = []): static
    {
        $spec = new static(self::TYPE_RADIO_GROUP, $name, $defaultLabel);
        $spec->defaultOptions = $defaultOptions;
        return $spec;
    }

    /**
     * Creates a Likert scale spec.
     *
     * @param string $name         HTML input name
     * @param string $defaultLabel Default question label
     * @param string $leftLabel    Default left-end label
     * @param string $middleLabel  Default centre label
     * @param string $rightLabel   Default right-end label
     * @param int    $scaleMin     Minimum scale value (default: 1)
     * @param int    $scaleMax     Maximum scale value (default: 5)
     */
    public static function likert(
        string $name,
        string $defaultLabel,
        string $leftLabel = 'Strongly disagree',
        string $middleLabel = '',
        string $rightLabel = 'Strongly agree',
        int $scaleMin = 1,
        int $scaleMax = 5,
    ): static {
        $spec = new static(self::TYPE_LIKERT, $name, $defaultLabel);
        $spec->defaultLeftLabel   = $leftLabel;
        $spec->defaultMiddleLabel = $middleLabel;
        $spec->defaultRightLabel  = $rightLabel;
        $spec->defaultScaleMin    = $scaleMin;
        $spec->defaultScaleMax    = $scaleMax;
        return $spec;
    }

    /**
     * Creates a select (dropdown) spec.
     *
     * @param string   $name           HTML input name
     * @param string   $defaultLabel   Default question label
     * @param string[] $defaultOptions Default option values
     */
    public static function select(string $name, string $defaultLabel, array $defaultOptions = []): static
    {
        $spec = new static(self::TYPE_SELECT, $name, $defaultLabel);
        $spec->defaultOptions = $defaultOptions;
        return $spec;
    }

    /**
     * Creates an info (display-only) spec that renders markdown content.
     * No form input is generated; the field is not submitted with the form.
     *
     * Pass $safeMarkdown for editor-authored content: it is then rendered in
     * markdown safe mode (no raw HTML, no javascript: links).
     *
     * @param string $name         Unique identifier for this item (not rendered as an input)
     * @param string $content      Markdown content to display
     * @param bool   $safeMarkdown Render in markdown safe mode
     */
    public static function info(string $name, string $content, bool $safeMarkdown = false): static
    {
        $spec = new static(self::TYPE_INFO, $name, '');
        $spec->defaultContent = $content;
        $spec->safeMarkdown = $safeMarkdown;
        return $spec;
    }

    /**
     * Creates a site-blocks (display-only) spec that renders a Kirby blocks field
     * stored on the site object.  No form input is generated; the field is not
     * submitted with the form.
     *
     * Typical use: editor-managed intro paragraphs placed before a group of fields.
     *
     * @param string $name          Unique identifier for this item (not rendered as an input)
     * @param string $siteFieldName The Kirby site field name holding the blocks content
     */
    public static function siteBlocks(string $name, string $siteFieldName): static
    {
        $spec = new static(self::TYPE_SITE_BLOCKS, $name, '');
        $spec->siteFieldName = $siteFieldName;
        return $spec;
    }

    /**
     * Creates a rating matrix spec (grid of rows × columns, one radio per row).
     *
     * Suitable for event-rating grids, before/after skill scales, and action matrices.
     * Each row is submitted as {name}[{sanitized_row_label}].
     *
     * @param string   $name         HTML input name (used as base for row keys)
     * @param string   $defaultLabel Default question label
     * @param string[] $rows         Row labels (items to rate)
     * @param string[] $columns      Column labels (scale options)
     */
    public static function ratingMatrix(
        string $name,
        string $defaultLabel,
        array $rows = [],
        array $columns = [],
    ): static {
        $spec = new static(self::TYPE_RATING_MATRIX, $name, $defaultLabel);
        $spec->defaultRows    = $rows;
        $spec->defaultColumns = $columns;
        return $spec;
    }

    // ── Fluent modifiers ────────────────────────────────────────────────────

    /**
     * Sets optional help text shown beneath this field's label.
     * May contain HTML (developer-authored, not user-supplied).
     *
     * @param string $help HTML or plain-text help content
     */
    public function help(string $help): static
    {
        $this->defaultHelp = $help;
        return $this;
    }

    /**
     * Marks this field as required.
     */
    public function required(): static
    {
        $this->required = true;
        return $this;
    }

    /**
     * Declares that a named property can be overridden by a panel editor.
     *
     * The generated blueprint field name is derived from the field name and
     * property name via getBlueprintFieldName().  If the panel field is
     * empty, $default is used (falling back to the constructor default).
     *
     * Supported property names per type:
     *  - All types:           'label'
     *  - checkbox/radio/select: 'options'
     *  - likert:              'leftLabel', 'middleLabel', 'rightLabel'
     *
     * @param string     $property The property name to expose in the panel
     * @param mixed|null $default  Override the hard-coded default for this property
     */
    public function overridable(string $property, mixed $default = null): static
    {
        $this->overridable[$property] = $default;
        return $this;
    }

    // ── Accessors ───────────────────────────────────────────────────────────

    /** @return string The field type constant */
    public function getType(): string
    {
        return $this->type;
    }

    /** @return string The HTML input name */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Returns the Kirby panel blueprint field name for a given overridable property.
     *
     * Convention: {snake_field_name}_{snake_property_name}
     * Example: 'knowledge_Start' + 'leftLabel' → 'knowledge_start_left_label'
     *
     * @param string $property camelCase or snake_case property name
     * @return string
     */
    public function getBlueprintFieldName(string $property): string
    {
        $snakeName     = strtolower($this->name);
        $snakeProperty = strtolower((string) preg_replace('/([A-Z])/', '_$1', $property));
        return $snakeName . '_' . ltrim($snakeProperty, '_');
    }

    /**
     * Returns a Kirby-blueprint-compatible field definition array for all
     * overridable properties of this spec.  Developers can use this output
     * to generate the YAML to paste into a page blueprint.
     *
     * @return array<string, array<string, mixed>>
     */
    public function toBlueprintFields(): array
    {
        $fields = [];

        foreach (array_keys($this->overridable) as $property) {
            $fieldName = $this->getBlueprintFieldName($property);
            $fields[$fieldName] = $this->buildBlueprintField($property);
        }

        return $fields;
    }

    /**
     * Resolves this spec against a map of panel-supplied override values,
     * returning an immutable ResolvedFormField ready for use in snippets.
     *
     * @param array<string, string> $panelValues  Map of property name → panel value string
     * @return ResolvedFormField
     */
    public function resolve(array $panelValues): ResolvedFormField
    {
        $label = $this->resolveProperty('label', $this->defaultLabel, $panelValues);

        return match ($this->type) {
            self::TYPE_TEXTBOX => new ResolvedFormField(
                type:      $this->type,
                name:      $this->name,
                label:     $label,
                required:  $this->required,
                inputType: $this->inputType,
                help:      $this->defaultHelp,
            ),
            self::TYPE_TEXTAREA => new ResolvedFormField(
                type:     $this->type,
                name:     $this->name,
                label:    $label,
                required: $this->required,
                help:     $this->defaultHelp,
            ),
            self::TYPE_CHECKBOX_GROUP, self::TYPE_RADIO_GROUP, self::TYPE_SELECT => new ResolvedFormField(
                type:     $this->type,
                name:     $this->name,
                label:    $label,
                required: $this->required,
                help:     $this->defaultHelp,
                options:  $this->resolveOptions($panelValues),
            ),
            self::TYPE_LIKERT => new ResolvedFormField(
                type:        $this->type,
                name:        $this->name,
                label:       $label,
                required:    $this->required,
                help:        $this->defaultHelp,
                leftLabel:   $this->resolveProperty('leftLabel', $this->defaultLeftLabel, $panelValues),
                middleLabel: $this->resolveProperty('middleLabel', $this->defaultMiddleLabel, $panelValues),
                rightLabel:  $this->resolveProperty('rightLabel', $this->defaultRightLabel, $panelValues),
                scaleMin:    $this->defaultScaleMin,
                scaleMax:    $this->defaultScaleMax,
            ),
            self::TYPE_INFO => new ResolvedFormField(
                type:         $this->type,
                name:         $this->name,
                label:        '',
                content:      $this->defaultContent,
                safeMarkdown: $this->safeMarkdown,
            ),
            self::TYPE_SITE_BLOCKS => new ResolvedFormField(
                type:    $this->type,
                name:    $this->name,
                label:   '',
                content: (string) site()->content()->get($this->siteFieldName)->toBlocks()->toHtml(),
            ),
            self::TYPE_RATING_MATRIX => new ResolvedFormField(
                type:     $this->type,
                name:     $this->name,
                label:    $label,
                required: $this->required,
                help:     $this->defaultHelp,
                rows:     $this->defaultRows,
                columns:  $this->defaultColumns,
            ),
            default => throw new \InvalidArgumentException("Unknown FormFieldSpec type: {$this->type}"),
        };
    }

    /**
     * Returns a map of all overridable property names to their defaults,
     * for use by BaseFormDefinition when looking up panel values.
     *
     * @return array<string, mixed>
     */
    public function getOverridableProperties(): array
    {
        return $this->overridable;
    }

    // ── Private helpers ─────────────────────────────────────────────────────

    /**
     * Resolves a single property: uses the panel value if non-empty, otherwise
     * uses the explicit override default from overridable(), otherwise falls
     * back to $hardDefault.
     *
     * @param string                $property
     * @param mixed                 $hardDefault
     * @param array<string, string> $panelValues
     * @return mixed
     */
    private function resolveProperty(string $property, mixed $hardDefault, array $panelValues): mixed
    {
        if (isset($panelValues[$property]) && $panelValues[$property] !== '') {
            return $panelValues[$property];
        }

        if (array_key_exists($property, $this->overridable) && $this->overridable[$property] !== null) {
            return $this->overridable[$property];
        }

        return $hardDefault;
    }

    /**
     * Resolves the options list, converting a newline-separated panel string
     * to an array when an override is present.
     *
     * @param array<string, string> $panelValues
     * @return string[]
     */
    private function resolveOptions(array $panelValues): array
    {
        if (isset($panelValues['options']) && $panelValues['options'] !== '') {
            return array_values(array_filter(array_map('trim', explode("\n", $panelValues['options']))));
        }

        if (array_key_exists('options', $this->overridable) && is_array($this->overridable['options'])) {
            return $this->overridable['options'];
        }

        return $this->defaultOptions;
    }

    /**
     * Builds a single Kirby blueprint field definition for a given property.
     *
     * The generated field includes a `placeholder` showing the current PHP default
     * so panel editors can see exactly what value will be used if the field is left
     * blank.  The `help` text also states the default explicitly.
     *
     * IMPORTANT — keeping placeholders in sync:
     * The `placeholder` value shown here is derived from the hard-coded default in
     * the FormFieldSpec declaration (e.g. in EventSignupDefinition::defineForm()).
     * If that PHP default is ever changed, the corresponding blueprint field's
     * `placeholder` and `help` text must be updated to match, so editors continue
     * to see the correct default value in the panel.
     *
     * @param string $property
     * @return array<string, mixed>
     */
    private function buildBlueprintField(string $property): array
    {
        if ($property === 'options') {
            $defaultOptionsText = implode("\n", $this->defaultOptions);
            return [
                'type'        => 'textarea',
                'label'       => "Options for \"{$this->defaultLabel}\" (one per line)",
                'placeholder' => $defaultOptionsText,
                'help'        => "Leave blank to use the default options. Default:\n{$defaultOptionsText}",
            ];
        }

        $defaultValueMap = [
            'label'       => $this->defaultLabel,
            'leftLabel'   => $this->defaultLeftLabel,
            'middleLabel' => $this->defaultMiddleLabel,
            'rightLabel'  => $this->defaultRightLabel,
        ];

        $labelMap = [
            'label'       => "Label for \"{$this->defaultLabel}\"",
            'leftLabel'   => "Left label for \"{$this->defaultLabel}\"",
            'middleLabel' => "Middle label for \"{$this->defaultLabel}\"",
            'rightLabel'  => "Right label for \"{$this->defaultLabel}\"",
        ];

        $default = $defaultValueMap[$property] ?? '';

        return [
            'type'        => 'text',
            'label'       => $labelMap[$property] ?? "Override: {$property} for \"{$this->defaultLabel}\"",
            'placeholder' => $default,
            'help'        => $default !== '' ? "Leave blank to use the default: \"{$default}\"" : 'Leave blank to use the default.',
        ];
    }
}
