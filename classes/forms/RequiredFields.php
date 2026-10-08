<?php

declare(strict_types=1);

namespace BSBI\WebBase\forms;

/**
 * Server-side check of required questions (the HTML `required` attribute can
 * be bypassed).
 *
 * A question in a section whose show-when condition the submission doesn't
 * meet was hidden from the respondent, so it isn't required.
 */
final class RequiredFields
{
    /** Field types whose answer is a list; empty means unanswered. */
    private const LIST_TYPES = ['checkbox-group', 'rating-matrix'];

    /**
     * Returns the labels of required questions left blank, in form order.
     *
     * @param array<ResolvedFormField|ResolvedFormSection> $groups   The form's getFieldGroups()
     * @param array<mixed>                                 $postData The submitted data
     * @return list<string>
     */
    public static function missing(array $groups, array $postData): array
    {
        $missing = [];
        foreach ($groups as $group) {
            if ($group instanceof ResolvedFormSection) {
                if (!self::isShown($group, $postData)) {
                    continue;
                }
                $fields = $group->fields;
            } else {
                $fields = [$group];
            }

            foreach ($fields as $field) {
                if ($field->required && self::isBlank($field, $postData[$field->name] ?? null)) {
                    $missing[] = $field->label;
                }
            }
        }
        return $missing;
    }

    /**
     * Returns true if the section was shown: it has no condition, or the
     * controlling question has the condition's answer.
     *
     * @param array<mixed> $postData
     */
    private static function isShown(ResolvedFormSection $section, array $postData): bool
    {
        if (!$section->isConditional()) {
            return true;
        }
        $answer = $postData[(string) $section->conditionField] ?? null;
        return is_scalar($answer) && (string) $answer === $section->conditionValue;
    }

    /**
     * Returns true if the posted value counts as no answer for this field.
     */
    private static function isBlank(ResolvedFormField $field, mixed $value): bool
    {
        if (in_array($field->type, self::LIST_TYPES, true)) {
            return empty($value);
        }
        return !is_scalar($value) || trim((string) $value) === '';
    }
}
