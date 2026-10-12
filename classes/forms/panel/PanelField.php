<?php

declare(strict_types=1);

namespace BSBI\WebBase\forms\panel;

use BSBI\WebBase\forms\FormFieldSpec;

/**
 * One field read from a panel block: the spec to render, plus the facts the
 * form-level checks need that a FormFieldSpec does not expose.
 */
final readonly class PanelField
{
    /** Field types that have options (choice questions). */
    public const CHOICE_TYPES = [
        FormFieldSpec::TYPE_CHECKBOX_GROUP,
        FormFieldSpec::TYPE_RADIO_GROUP,
        FormFieldSpec::TYPE_SELECT,
    ];

    /**
     * @param FormFieldSpec $spec    The spec, with editor text already escaped
     * @param string        $key     POST key (the spec's name)
     * @param string        $type    One of the FormFieldSpec::TYPE_* constants
     * @param string[]      $options Raw (unescaped) options for choice fields, as submitted
     * @param string        $label    Raw label (display-only text: its text), for problem
     *                                messages, panel summaries and stored submissions
     * @param string        $reportAs Export column chosen by the editor; blank means the key
     */
    public function __construct(
        public FormFieldSpec $spec,
        public string $key,
        public string $type,
        public array $options = [],
        public string $label = '',
        public string $reportAs = '',
    ) {
    }

    /**
     * Returns true if this field produces a POST value (i.e. is not display-only).
     */
    public function isSubmittable(): bool
    {
        return !in_array($this->type, [FormFieldSpec::TYPE_INFO, FormFieldSpec::TYPE_SITE_BLOCKS], true);
    }

    /**
     * Returns true if a section can be shown or hidden by this field's value.
     */
    /**
     * Returns true if this is a choice question with options (radio, dropdown, checkboxes).
     */
    public function hasOptions(): bool
    {
        return in_array($this->type, self::CHOICE_TYPES, true);
    }

    /**
     * Returns true if this field can control a section's show-when condition.
     */
    public function canControlConditions(): bool
    {
        return in_array($this->type, [FormFieldSpec::TYPE_RADIO_GROUP, FormFieldSpec::TYPE_SELECT], true);
    }
}
