<?php

declare(strict_types=1);

namespace BSBI\WebBase\forms;

/**
 * An immutable value object representing a fully-resolved form section,
 * with all its fields resolved against panel overrides.
 *
 * Passed from BaseFormDefinition::getFieldGroups() to template snippets.
 */
readonly class ResolvedFormSection
{
    /**
     * @param string               $id             Unique section identifier (used as HTML id prefix)
     * @param string               $title          Optional section title rendered as <legend>
     * @param ResolvedFormField[]  $fields         The resolved fields belonging to this section
     * @param string|null          $conditionField HTML name of the controlling field, or null if unconditional
     * @param string|null          $conditionValue The value the controlling field must have to show this section
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly array $fields,
        public readonly ?string $conditionField,
        public readonly ?string $conditionValue,
    ) {
    }

    /**
     * Returns true if this section has a display condition.
     */
    public function isConditional(): bool
    {
        return $this->conditionField !== null;
    }

    /**
     * A copy whose fields show what was posted ({@see ResolvedFormField::withSubmitted()}).
     *
     * @param array<mixed> $postData
     */
    public function withSubmitted(array $postData): self
    {
        return new self(
            $this->id,
            $this->title,
            array_map(static fn (ResolvedFormField $field): ResolvedFormField => $field->withSubmitted($postData), $this->fields),
            $this->conditionField,
            $this->conditionValue,
        );
    }
}
