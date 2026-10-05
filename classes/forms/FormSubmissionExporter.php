<?php

declare(strict_types=1);

namespace BSBI\WebBase\forms;

use Kirby\Cms\Page;

/**
 * Builds the rows of the form submissions CSV exports.
 *
 * Each submission's items are either legacy `{question, answer}` pairs (every
 * hand-written form) or `{question, answer, key, column}` items (forms with a
 * submission column map, see FormSubmissionBuilder).
 *
 * Wide format: one row per submission, one column per question. Legacy items
 * are columned by question text, exactly as before; items with a column are
 * columned by that column, so the same question lines up across forms and
 * survives a label edit. A column's header is its "Report as" name when one
 * was chosen (column differs from key), otherwise the most recent label the
 * question was asked with. Columns appear in first-seen order.
 *
 * Long format: one row per answer, for pivoting in a spreadsheet.
 *
 * @phpstan-type Submission array{formType: string, title: string, date: string, items: list<array<string, mixed>>}
 */
final readonly class FormSubmissionExporter
{
    /** Shown for submissions stored without a form type. */
    public const UNTYPED = '(untyped)';

    /**
     * Reads a form_submission page into the shape the export methods take.
     *
     * @param Page $page A form_submission page
     * @return Submission
     */
    public static function record(Page $page): array
    {
        $items = [];
        foreach ($page->content()->get('submission')->yaml() as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $items[] = $item;
            }
        }

        $date = $page->modified('Y-m-d H:i:s');

        return [
            'formType' => (string) $page->content()->get('form_type')->value(),
            'title'    => (string) $page->title()->value(),
            'date'     => is_string($date) ? $date : '',
            'items'    => $items,
        ];
    }

    /**
     * Returns the header row and one row per submission.
     *
     * @param list<Submission> $submissions In display order
     * @param bool             $withFormType Prefix each row with the form type
     * @return list<list<string>>
     */
    public function wide(array $submissions, bool $withFormType): array
    {
        /** @var array<string, string> $headers Column id => header, in first-seen order */
        $headers = [];
        /** @var array<string, string> $headerDates Column id => date of the label in $headers */
        $headerDates = [];
        $rows = [];

        foreach ($submissions as $submission) {
            $answers = [];
            foreach ($submission['items'] as $item) {
                [$id, $header, $fixed] = $this->column($item);

                if (!isset($headers[$id])) {
                    $headers[$id] = $header;
                    $headerDates[$id] = $submission['date'];
                } elseif (!$fixed && $submission['date'] >= $headerDates[$id]) {
                    $headers[$id] = $header;
                    $headerDates[$id] = $submission['date'];
                }

                $answers[$id] = $this->string($item['answer'] ?? '');
            }
            $rows[] = ['submission' => $submission, 'answers' => $answers];
        }

        $prefix = $withFormType ? ['Form Type', 'Submission'] : ['Submission'];
        $csvRows = [array_merge($prefix, array_values($headers))];

        foreach ($rows as $row) {
            $csvRow = $withFormType
                ? [$this->formType($row['submission']), $row['submission']['title']]
                : [$row['submission']['title']];
            foreach (array_keys($headers) as $id) {
                $csvRow[] = $row['answers'][$id] ?? '';
            }
            $csvRows[] = $csvRow;
        }

        return $csvRows;
    }

    /**
     * Returns the header row and one row per answer.
     *
     * @param list<Submission> $submissions In display order
     * @return list<list<string>>
     */
    public function long(array $submissions): array
    {
        $csvRows = [['Form Type', 'Submission', 'Date', 'Column', 'Question', 'Answer']];

        foreach ($submissions as $submission) {
            foreach ($submission['items'] as $item) {
                $question = $this->string($item['question'] ?? '');
                $column = $this->string($item['column'] ?? '');
                $csvRows[] = [
                    $this->formType($submission),
                    $submission['title'],
                    $submission['date'],
                    $column !== '' ? $column : $question,
                    $question,
                    $this->string($item['answer'] ?? ''),
                ];
            }
        }

        return $csvRows;
    }

    /**
     * Returns an item's column id, its candidate header, and whether that
     * header is fixed (a "Report as" name) rather than a label that may change.
     *
     * Ids are prefixed so a legacy question and a column with the same text
     * stay separate columns.
     *
     * @param array<string, mixed> $item
     * @return array{0: string, 1: string, 2: bool}
     */
    private function column(array $item): array
    {
        $question = $this->string($item['question'] ?? '');
        $column = $this->string($item['column'] ?? '');

        if ($column === '') {
            return ['q:' . $question, $question, true];
        }

        $isReportAs = $column !== $this->string($item['key'] ?? '');
        return ['c:' . $column, $isReportAs ? $column : $question, $isReportAs];
    }

    /**
     * Returns the submission's form type, or the untyped marker.
     *
     * @param Submission $submission
     */
    private function formType(array $submission): string
    {
        return $submission['formType'] !== '' ? $submission['formType'] : self::UNTYPED;
    }

    /**
     * Returns a stored value as a string (YAML may give numbers or null).
     *
     * @param mixed $value
     */
    private function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
