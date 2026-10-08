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
 * When that hides only one count, the next smallest is hidden too, so it
 * can't be recovered by subtraction from "answered by" (suppress()); a Likert
 * mean and median with counts hidden are left out for the same reason.
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

        $shown = $suppressed ? self::suppress(array_values($counts)) : array_values($counts);
        $options = [];
        foreach (array_keys($counts) as $i => $option) {
            $options[] = self::counted((string) $option, $shown[$i], count($given));
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

        $shown = $suppressed ? self::suppress(array_values($counts)) : array_values($counts);
        $points = [];
        foreach (array_keys($counts) as $i => $value) {
            $point = self::counted((string) $value, $shown[$i], $n);
            $points[] = ['value' => (int) $value, 'count' => $point['count'], 'percent' => $point['percent']];
        }
        // A mean or median with counts hidden would help work them out.
        $hidden = in_array(null, $shown, true);

        return [
            'chart'      => 'columns',
            'points'     => $points,
            'mean'       => $n === 0 || $hidden ? null : round(array_sum($values) / $n, 1),
            'median'     => $hidden ? null : $median,
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
                'counts'   => $suppressed ? self::suppress(array_values($counts[$rowKey])) : array_values($counts[$rowKey]),
                'answered' => $answered,
            ];
        }
        return ['chart' => 'stacked', 'columns' => $columns, 'rows' => $out];
    }

    /**
     * @return array{label: string, count: int|null, percent: int|null}
     */
    private static function counted(string $label, ?int $count, int $of): array
    {
        return [
            'label'   => $label,
            'count'   => $count,
            'percent' => $count === null ? null : ($of === 0 ? 0 : (int) round($count * 100 / $of)),
        ];
    }

    /**
     * Returns the counts with small ones hidden (null): every count from 1 to
     * SMALL_COUNT - 1, and, when that hides exactly one, the smallest other
     * non-zero count too, so the hidden one can't be worked out by subtracting
     * the shown counts from the total (secondary suppression).
     *
     * @param list<int> $counts
     * @return list<int|null>
     */
    public static function suppress(array $counts): array
    {
        $shown = array_map(static fn(int $c): ?int => $c > 0 && $c < self::SMALL_COUNT ? null : $c, $counts);
        if (count(array_filter($shown, static fn(?int $c): bool => $c === null)) === 1) {
            $partner = null;
            foreach ($shown as $i => $c) {
                if ($c !== null && $c > 0 && ($partner === null || $c < $shown[$partner])) {
                    $partner = $i;
                }
            }
            if ($partner !== null) {
                $shown[$partner] = null;
            }
        }
        return array_values($shown);
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
