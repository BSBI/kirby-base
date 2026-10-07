<?php

declare(strict_types=1);

namespace BSBI\WebBase\forms\panel;

use BSBI\WebBase\forms\FormFieldSpec;
use Kirby\Cms\Page;

/**
 * Describes what a library section gives a form, for the section's panel
 * page: its questions in order (inherited ones first, marked with the section
 * they come from) and the section's own problems (variation chain, unreadable
 * blocks, field names that are invalid or used twice).
 */
final readonly class PanelSectionCheck
{
    /** Editor-facing names of the question types. */
    private const TYPE_NAMES = [
        FormFieldSpec::TYPE_TEXTBOX        => 'Text input',
        FormFieldSpec::TYPE_TEXTAREA       => 'Text area',
        FormFieldSpec::TYPE_RADIO_GROUP    => 'Radio buttons',
        FormFieldSpec::TYPE_CHECKBOX_GROUP => 'Checkboxes',
        FormFieldSpec::TYPE_SELECT         => 'Dropdown',
        FormFieldSpec::TYPE_LIKERT         => 'Likert scale',
        FormFieldSpec::TYPE_RATING_MATRIX  => 'Rating grid',
        FormFieldSpec::TYPE_INFO           => 'Text (display only)',
    ];

    /**
     * @param SectionPageResolver $resolver Finds base sections named in `extends`
     */
    public function __construct(private SectionPageResolver $resolver)
    {
    }

    /**
     * Returns the section's questions and problems.
     *
     * @param Page $section A library section page
     * @return array{questions: list<array{label: string, type: string, from: string}>, problems: list<string>}
     */
    public function check(Page $section): array
    {
        $problems = new FormProblems();
        $entries = (new PanelSectionReader($this->resolver))->readWithOrigins($section, $problems);

        $questions = [];
        $seen = [];
        foreach ($entries as $entry) {
            $field = $entry['field'];
            $questions[] = [
                'label' => $field->label,
                'type'  => self::TYPE_NAMES[$field->type] ?? $field->type,
                'from'  => $entry['from'],
            ];

            $where = $entry['from'] !== '' ? sprintf('Question "%s" (from "%s")', $field->label, $entry['from'])
                : sprintf('Question "%s"', $field->label);
            if (isset($seen[$field->key])) {
                $problems->add(sprintf(
                    '%s: the field name "%s" is used more than once in this section; forms keep only the first.',
                    $where,
                    $field->key
                ));
            } elseif (!PanelFieldReader::isValidKey($field->key)) {
                $problems->add(sprintf(
                    '%s: the field name "%s" is not allowed (use letters, digits and underscores, '
                    . 'starting with a letter); forms leave the question out.',
                    $where,
                    $field->key
                ));
            }
            $seen[$field->key] = true;
        }

        return ['questions' => $questions, 'problems' => array_values($problems->all())];
    }
}
