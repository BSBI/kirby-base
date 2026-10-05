<?php

declare(strict_types=1);

namespace BSBI\WebBase\forms;

/**
 * Turns form POST data into the items stored on a form_submission page.
 *
 * Without a column map (every hand-written form today) each POST key except
 * `csrf` and `submit` becomes `{question, answer}`, the question being the key
 * title-cased — exactly what createFormSubmission() always stored.
 *
 * With a map (see BaseFormDefinition::getSubmissionColumns()) only the mapped
 * keys are stored, in map order, each as `{question, answer, key, column}`:
 * the label the respondent saw, and the column the CSV export files it under.
 */
final readonly class FormSubmissionBuilder
{
    /** POST keys the form machinery uses itself. */
    private const EXCLUDED_KEYS = ['submit', 'csrf'];

    /**
     * Returns the submission items for the given POST data.
     *
     * @param array<mixed>                                       $postData The request data
     * @param array<string, array{label: string, column: string}> $columns  Key => label and export column
     * @return list<array<string, mixed>>
     */
    public function items(array $postData, array $columns = []): array
    {
        if ($columns === []) {
            return $this->legacyItems($postData);
        }

        $items = [];
        foreach ($columns as $key => $column) {
            $items[] = [
                'question' => $column['label'],
                'answer'   => $this->answer($postData[$key] ?? ''),
                'key'      => $key,
                'column'   => $column['column'],
            ];
        }
        return $items;
    }

    /**
     * Returns items for every POST key, as createFormSubmission() always built them.
     *
     * @param array<mixed> $postData
     * @return list<array<string, mixed>>
     */
    private function legacyItems(array $postData): array
    {
        $items = [];
        foreach ($postData as $inputName => $inputValue) {
            if (in_array($inputName, self::EXCLUDED_KEYS) || empty($inputName)) {
                continue;
            }
            $spacedString = str_replace(['-', '_'], ' ', (string) $inputName);

            $items[] = [
                'question' => ucwords($spacedString),
                'answer'   => is_array($inputValue) ? implode(', ', $inputValue) : $inputValue,
            ];
        }
        return $items;
    }

    /**
     * Flattens a posted value into one cell: lists are joined with commas, and
     * keyed arrays (rating-matrix rows) keep each row name, as "row: answer".
     * Anything nested deeper is not something the form could have sent, so it
     * is dropped.
     *
     * @param mixed $value
     */
    private function answer(mixed $value): string
    {
        if (is_scalar($value)) {
            return (string) $value;
        }
        if (!is_array($value)) {
            return '';
        }

        $parts = [];
        foreach ($value as $name => $part) {
            if (!is_scalar($part)) {
                continue;
            }
            $parts[] = is_string($name) ? $name . ': ' . $part : (string) $part;
        }
        return implode(', ', $parts);
    }
}
