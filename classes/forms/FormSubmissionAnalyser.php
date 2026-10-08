<?php

declare(strict_types=1);

namespace BSBI\WebBase\forms;

/**
 * Summarises a form type's responses question by question, for the panel's
 * analysis view: counts per option, Likert distributions, grid rows, and
 * the free-text answers.
 *
 * It reads the exporter's table (FormSubmissionExporter::table()), so its
 * columns are exactly the CSV's, old answers included. Each column's shape
 * comes from the forms' definitions (FormAnalysis); a column with no shape
 * (an old question, or a hand-written form) is inferred: a few distinct
 * answers that repeat make a choice question, anything else free text.
 *
 * Small counts on EDI questions are hidden: a count from 1 to SMALL_COUNT - 1
 * becomes null, with its percentage, so a small event can't single anyone out.
 *
 * @phpstan-type Shape array{
 *     kind: string,
 *     options?: list<string>,
 *     rows?: array<string, string>,
 *     columns?: list<string>,
 *     scaleMin?: int,
 *     scaleMax?: int,
 *     leftLabel?: string,
 *     rightLabel?: string
 * }
 */
final readonly class FormSubmissionAnalyser
{
    /** EDI counts below this are hidden. */
    public const SMALL_COUNT = 5;

    /** A column with no shape is a choice question if it has at most this many distinct answers, each given twice on average. */
    public const INFER_MAX_CHOICES = 12;

    /** A single-choice question with at most this many options is drawn as a donut. */
    public const DONUT_MAX_OPTIONS = 5;

    /**
     * @param array<string, Shape> $shapes     Column id => its question's shape
     * @param list<string>         $ediColumns Column ids whose small counts are hidden
     * @param bool                 $allEdi     Hide small counts on every column (the edi form type)
     */
    public function __construct(
        private array $shapes,
        private array $ediColumns = [],
        private bool $allEdi = false,
    ) {
    }

    /**
     * Returns the summary: the number of responses, the first and last dates,
     * responses per month, and one summary per question in column order.
     *
     * @param array{headers: array<string, string>, keys: array<string, string>, rows: list<array{submission: array{date: string}, answers: array<string, string>}>} $table
     * @return array{total: int, first: string, last: string, perMonth: list<array{month: string, count: int}>, questions: list<array<string, mixed>>}
     */
    public function analyse(array $table): array
    {
        $dates = array_map(static fn(array $row): string => $row['submission']['date'], $table['rows']);
        $sorted = $dates;
        sort($sorted);
        $perMonth = [];
        foreach ($sorted as $date) {
            $month = substr($date, 0, 7);
            $perMonth[$month] = ($perMonth[$month] ?? 0) + 1;
        }

        $questions = [];
        foreach ($table['headers'] as $id => $label) {
            $answers = [];
            foreach ($table['rows'] as $row) {
                $answers[] = ['text' => trim($row['answers'][$id] ?? ''), 'date' => $row['submission']['date']];
            }
            $questions[] = $this->question((string) $id, $label, $answers, count($table['rows']));
        }

        return [
            'total'     => count($table['rows']),
            'first'     => substr($sorted[0] ?? '', 0, 10),
            'last'      => substr($sorted[count($sorted) - 1] ?? '', 0, 10),
            'perMonth'  => array_map(static fn(string $m, int $c): array => ['month' => $m, 'count' => $c], array_keys($perMonth), $perMonth),
            'questions' => $questions,
        ];
    }

    /**
     * @param list<array{text: string, date: string}> $answers Every response's answer ('' if none)
     * @return array<string, mixed>
     */
    private function question(string $id, string $label, array $answers, int $total): array
    {
        $given = array_values(array_filter($answers, static fn(array $a): bool => $a['text'] !== ''));
        $shape = $this->shapes[$id] ?? null;
        $inferred = $shape === null;
        if ($shape === null) {
            // A choice question's answers repeat; names and free text mostly don't.
            $distinct = count(array_unique(array_column($given, 'text')));
            $shape = $distinct <= self::INFER_MAX_CHOICES && count($given) >= 2 * $distinct
                ? ['kind' => 'choice', 'options' => []]
                : ['kind' => 'text'];
        }
        $suppressed = $this->allEdi || in_array($id, $this->ediColumns, true);

        $base = [
            'id'         => $id,
            'label'      => $label,
            'kind'       => $shape['kind'],
            'answered'   => count($given),
            'total'      => $total,
            'inferred'   => $inferred,
            'suppressed' => $suppressed,
        ];

        return $base + match ($shape['kind']) {
            'choice', 'multi' => $this->choices($shape, $given, $suppressed),
            'likert'          => $this->likert($shape, $given, $suppressed),
            'grid'            => $this->grid($shape, $given, $suppressed),
            default           => ['chart' => 'list', 'answers' => $this->newestFirst($given)],
        };
    }

    /**
     * @param Shape                                   $shape
     * @param list<array{text: string, date: string}> $given
     * @return array<string, mixed>
     */
    private function choices(array $shape, array $given, bool $suppressed): array
    {
        $multiple = $shape['kind'] === 'multi';
        $counts = array_fill_keys($shape['options'] ?? [], 0);
        foreach ($given as $answer) {
            $picked = $multiple ? self::split($answer['text'], $shape['options'] ?? []) : [$answer['text']];
            foreach ($picked as $option) {
                $counts[$option] = ($counts[$option] ?? 0) + 1;
            }
        }

        $options = [];
        foreach ($counts as $option => $count) {
            $options[] = $this->counted((string) $option, $count, count($given), $suppressed);
        }
        $chart = !$multiple && count($options) <= self::DONUT_MAX_OPTIONS ? 'donut' : 'bars';

        return ['chart' => $chart, 'multiple' => $multiple, 'options' => $options];
    }

    /**
     * @param Shape                                   $shape
     * @param list<array{text: string, date: string}> $given
     * @return array<string, mixed>
     */
    private function likert(array $shape, array $given, bool $suppressed): array
    {
        $min = $shape['scaleMin'] ?? 1;
        $max = $shape['scaleMax'] ?? 5;
        $counts = array_fill_keys(range($min, $max), 0);
        $values = [];
        foreach ($given as $answer) {
            if (preg_match('/^-?\d+$/', $answer['text']) === 1 && isset($counts[(int) $answer['text']])) {
                $counts[(int) $answer['text']]++;
                $values[] = (int) $answer['text'];
            }
        }
        sort($values);
        $n = count($values);
        $middle = intdiv($n, 2);
        $median = $n === 0 ? null : ($n % 2 === 1
            ? (float) $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2);

        $points = [];
        foreach ($counts as $value => $count) {
            $points[] = ['value' => (int) $value] + $this->counted((string) $value, $count, $n, $suppressed);
        }
        foreach ($points as $i => $point) {
            unset($points[$i]['label']);
        }

        return [
            'chart'      => 'columns',
            'points'     => $points,
            'mean'       => $n === 0 ? null : round(array_sum($values) / $n, 1),
            'median'     => $median,
            'leftLabel'  => $shape['leftLabel'] ?? '',
            'rightLabel' => $shape['rightLabel'] ?? '',
        ];
    }

    /**
     * @param Shape                                   $shape
     * @param list<array{text: string, date: string}> $given
     * @return array<string, mixed>
     */
    private function grid(array $shape, array $given, bool $suppressed): array
    {
        $rows = $shape['rows'] ?? [];
        $columns = $shape['columns'] ?? [];
        $counts = [];
        foreach (array_keys($rows) as $rowKey) {
            $counts[$rowKey] = array_fill_keys($columns, 0);
        }
        foreach ($given as $answer) {
            foreach (self::gridAnswers($answer['text'], array_keys($rows)) as $rowKey => $value) {
                if (isset($counts[$rowKey][$value])) {
                    $counts[$rowKey][$value]++;
                }
            }
        }

        $out = [];
        foreach ($rows as $rowKey => $rowLabel) {
            $answered = array_sum($counts[$rowKey]);
            $out[] = [
                'label'    => $rowLabel,
                'counts'   => array_map(fn(int $c): ?int => $this->hide($c, $suppressed), array_values($counts[$rowKey])),
                'answered' => $answered,
            ];
        }
        return ['chart' => 'stacked', 'columns' => $columns, 'rows' => $out];
    }

    /**
     * @return array{label: string, count: int|null, percent: int|null}
     */
    private function counted(string $label, int $count, int $of, bool $suppressed): array
    {
        $shown = $this->hide($count, $suppressed);
        return [
            'label'   => $label,
            'count'   => $shown,
            'percent' => $shown === null ? null : ($of === 0 ? 0 : (int) round($count * 100 / $of)),
        ];
    }

    private function hide(int $count, bool $suppressed): ?int
    {
        return $suppressed && $count > 0 && $count < self::SMALL_COUNT ? null : $count;
    }

    /**
     * @param list<array{text: string, date: string}> $given
     * @return list<array{text: string, date: string}>
     */
    private function newestFirst(array $given): array
    {
        usort($given, static fn(array $a, array $b): int => strcmp($b['date'], $a['date']));
        return array_map(static fn(array $a): array => ['text' => $a['text'], 'date' => substr($a['date'], 0, 10)], $given);
    }

    /**
     * Splits a checkbox answer ("a, b") into its options, recognising options
     * that contain ", " themselves; anything else becomes an option of its own.
     *
     * @param list<string> $options
     * @return list<string>
     */
    public static function split(string $answer, array $options): array
    {
        $picked = [];
        $rest = ', ' . $answer . ', ';
        // Longest options first, so "Sedges, rushes" is found before a shorter one inside it.
        usort($options, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($options as $option) {
            $needle = ', ' . $option . ', ';
            if (str_contains($rest, $needle)) {
                $picked[$option] = true;
                $rest = (string) preg_replace('/' . preg_quote($needle, '/') . '/', ', ', $rest, 1);
            }
        }
        foreach (explode(', ', trim($rest, ', ')) as $other) {
            if ($other !== '') {
                $picked[$other] = true;
            }
        }
        return array_keys($picked);
    }

    /**
     * Reads a grid answer ("venue: Good, leader: Poor, sadly") into row key =>
     * value, finding each known row key where it starts an entry, so values
     * may contain ", ".
     *
     * @param list<string> $rowKeys
     * @return array<string, string>
     */
    public static function gridAnswers(string $answer, array $rowKeys): array
    {
        $starts = [];
        foreach ($rowKeys as $rowKey) {
            $marker = $rowKey . ': ';
            if (str_starts_with($answer, $marker)) {
                $starts[0] = $rowKey;
            }
            $offset = 0;
            while (($at = strpos($answer, ', ' . $marker, $offset)) !== false) {
                $starts[$at + 2] = $rowKey;
                $offset = $at + 2;
            }
        }
        ksort($starts);
        $positions = array_keys($starts);
        $values = [];
        foreach ($positions as $i => $start) {
            $rowKey = $starts[$start];
            $from = $start + strlen($rowKey) + 2;
            $to = isset($positions[$i + 1]) ? $positions[$i + 1] - 2 : strlen($answer);
            $values[$rowKey] = substr($answer, $from, $to - $from);
        }
        return $values;
    }
}
