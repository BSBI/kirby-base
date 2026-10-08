<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\forms;

use BSBI\WebBase\forms\FormSubmissionAnalyser;
use BSBI\WebBase\forms\FormSubmissionExporter;
use PHPUnit\Framework\TestCase;

/**
 * Tests for FormSubmissionAnalyser: per-question summaries of a form type's
 * responses, from the exporter's columns and each column's shape.
 */
final class FormSubmissionAnalyserTest extends TestCase
{
    private const SHAPES = [
        'c:enjoyed' => ['kind' => 'choice', 'options' => ['Yes', 'Mostly', 'No']],
        'c:topics'  => ['kind' => 'multi', 'options' => ['Grasses', 'Sedges, rushes', 'Ferns']],
        'c:skill'   => ['kind' => 'likert', 'scaleMin' => 0, 'scaleMax' => 4, 'leftLabel' => 'None', 'rightLabel' => 'Expert'],
        'c:rating'  => ['kind' => 'grid', 'rows' => ['venue' => 'Venue', 'leader_trainer' => 'Leader/trainer'], 'columns' => ['Good', 'Poor, sadly']],
        'c:comments' => ['kind' => 'text'],
        'c:when'    => ['kind' => 'date'],
    ];

    public function testTheHeaderCountsResponsesByMonth(): void
    {
        $result = $this->analyse([
            $this->response('2026-07-30 10:00:00', ['enjoyed' => 'Yes']),
            $this->response('2026-08-02 10:00:00', ['enjoyed' => 'No']),
            $this->response('2026-08-20 10:00:00', ['enjoyed' => 'Yes']),
        ]);

        $this->assertSame(3, $result['total']);
        $this->assertSame('2026-07-30', $result['first']);
        $this->assertSame('2026-08-20', $result['last']);
        $this->assertSame([['month' => '2026-07', 'count' => 1], ['month' => '2026-08', 'count' => 2]], $result['perMonth']);
    }

    public function testASingleChoiceQuestionCountsEachOptionInOrder(): void
    {
        $question = $this->question('c:enjoyed', [
            ['enjoyed' => 'Yes'], ['enjoyed' => 'Yes'], ['enjoyed' => 'No'], ['enjoyed' => ''], ['enjoyed' => 'Kind of'],
        ]);

        $this->assertSame('choice', $question['kind']);
        $this->assertSame('donut', $question['chart']);
        $this->assertSame(4, $question['answered']);
        $this->assertSame(5, $question['total']);
        $this->assertSame([
            ['label' => 'Yes', 'count' => 2, 'percent' => 50],
            ['label' => 'Mostly', 'count' => 0, 'percent' => 0],
            ['label' => 'No', 'count' => 1, 'percent' => 25],
            ['label' => 'Kind of', 'count' => 1, 'percent' => 25],
        ], $question['options']);
    }

    public function testMoreThanFiveOptionsDrawBars(): void
    {
        $analyser = new FormSubmissionAnalyser(['c:age' => ['kind' => 'choice', 'options' => ['a', 'b', 'c', 'd', 'e', 'f']]]);

        $result = $analyser->analyse($this->table([$this->response('2026-01-01 00:00:00', ['age' => 'a'])]));

        $this->assertSame('bars', $result['questions'][0]['chart']);
    }

    public function testCheckboxAnswersAreSplitOnTheirOptionsEvenWithCommas(): void
    {
        $question = $this->question('c:topics', [
            ['topics' => 'Grasses, Sedges, rushes'],
            ['topics' => 'Sedges, rushes'],
            ['topics' => 'Ferns, Mosses'],
        ]);

        $this->assertSame('bars', $question['chart']);
        $this->assertTrue($question['multiple']);
        $this->assertSame([
            ['label' => 'Grasses', 'count' => 1, 'percent' => 33],
            ['label' => 'Sedges, rushes', 'count' => 2, 'percent' => 67],
            ['label' => 'Ferns', 'count' => 1, 'percent' => 33],
            ['label' => 'Mosses', 'count' => 1, 'percent' => 33],
        ], $question['options']);
    }

    public function testALikertQuestionHasEachPointAndItsMeanAndMedian(): void
    {
        $question = $this->question('c:skill', [['skill' => '0'], ['skill' => '2'], ['skill' => '3'], ['skill' => '3'], ['skill' => '']]);

        $this->assertSame('columns', $question['chart']);
        $this->assertSame([0, 1, 2, 3, 4], array_column($question['points'], 'value'));
        $this->assertSame([1, 0, 1, 2, 0], array_column($question['points'], 'count'));
        $this->assertSame(2.0, $question['mean']);
        $this->assertSame(2.5, $question['median']);
        $this->assertSame(['None', 'Expert'], [$question['leftLabel'], $question['rightLabel']]);
    }

    public function testAGridCountsEachRowEvenWhenAnswersHaveCommas(): void
    {
        $question = $this->question('c:rating', [
            ['rating' => 'venue: Good, leader_trainer: Poor, sadly'],
            ['rating' => 'venue: Poor, sadly, leader_trainer: Good'],
            ['rating' => 'venue: Good'],
        ]);

        $this->assertSame('stacked', $question['chart']);
        $this->assertSame(['Good', 'Poor, sadly'], $question['columns']);
        $this->assertSame([
            ['label' => 'Venue', 'counts' => [2, 1], 'answered' => 3],
            ['label' => 'Leader/trainer', 'counts' => [1, 1], 'answered' => 2],
        ], $question['rows']);
    }

    public function testFreeTextAndDatesListTheAnswersNewestFirst(): void
    {
        $result = $this->analyse([
            $this->response('2026-01-01 00:00:00', ['comments' => 'First', 'when' => '2025-12-30']),
            $this->response('2026-03-01 00:00:00', ['comments' => '']),
            $this->response('2026-02-01 00:00:00', ['comments' => 'Second']),
        ]);
        $comments = $this->find($result, 'c:comments');

        $this->assertSame('list', $comments['chart']);
        $this->assertSame(2, $comments['answered']);
        $this->assertSame(
            [['text' => 'Second', 'date' => '2026-02-01'], ['text' => 'First', 'date' => '2026-01-01']],
            $comments['answers']
        );
        $this->assertSame('date', $this->find($result, 'c:when')['kind']);
    }

    public function testAColumnWithoutAShapeIsInferredFromItsAnswers(): void
    {
        $analyser = new FormSubmissionAnalyser([]);
        $responses = [];
        foreach (['Yes', 'No', 'Yes', 'Abstain', 'Yes', 'No'] as $vote) {
            $responses[] = ['date' => '2026-01-01 00:00:00', 'items' => [['question' => '1. Accounts', 'answer' => $vote]]];
        }
        $many = [];
        foreach (range(1, 13) as $n) {
            $many[] = ['date' => '2026-01-01 00:00:00', 'items' => [['question' => 'Thoughts', 'answer' => 'Idea ' . $n]]];
        }

        $votes = $analyser->analyse($this->table($responses))['questions'][0];
        $thoughts = $analyser->analyse($this->table($many))['questions'][0];

        $this->assertSame('choice', $votes['kind']);
        $this->assertTrue($votes['inferred']);
        $this->assertSame(['Yes', 'No', 'Abstain'], array_column($votes['options'], 'label'));
        $this->assertSame('text', $thoughts['kind']);

        // Answers that don't repeat (names, say) are text, however few.
        $names = [
            ['date' => '2026-01-01 00:00:00', 'items' => [['question' => 'Your name', 'answer' => 'Ann']]],
            ['date' => '2026-01-01 00:00:00', 'items' => [['question' => 'Your name', 'answer' => 'Bob']]],
        ];
        $this->assertSame('text', $analyser->analyse($this->table($names))['questions'][0]['kind']);
    }

    public function testAnUnshapedColumnOfSmallWholeNumbersIsInferredAsAScale(): void
    {
        $responses = [];
        foreach (['5', '4', '5', '3', '5', '1'] as $score) {
            $responses[] = ['date' => '2026-01-01 00:00:00', 'items' => [['question' => 'Objectives Comms', 'answer' => $score]]];
        }

        $question = (new FormSubmissionAnalyser([]))->analyse($this->table($responses))['questions'][0];

        $this->assertSame('likert', $question['kind']);
        $this->assertTrue($question['inferred']);
        $this->assertSame([1, 2, 3, 4, 5], array_column($question['points'], 'value'));
        $this->assertSame([1, 0, 1, 1, 3], array_column($question['points'], 'count'));
    }

    public function testSmallEdiCountsAreHiddenAndOtherQuestionsStayExact(): void
    {
        $analyser = new FormSubmissionAnalyser(
            ['c:gender' => ['kind' => 'choice', 'options' => ['Woman', 'Man', 'Prefer not to say']]] + self::SHAPES,
            ['c:gender']
        );
        $responses = [];
        foreach (array_merge(array_fill(0, 6, 'Woman'), ['Man', 'Man']) as $i => $gender) {
            $responses[] = $this->response('2026-01-01 00:00:00', ['gender' => $gender, 'enjoyed' => $i === 0 ? 'No' : 'Yes']);
        }

        $result = $analyser->analyse($this->table($responses));
        $gender = $this->find($result, 'c:gender');
        $enjoyed = $this->find($result, 'c:enjoyed');

        $this->assertTrue($gender['suppressed']);
        // "Man" (2) is hidden; with only one count hidden, "answered by 8" minus the
        // others would reveal it, so the next smallest ("Woman", 6) is hidden too.
        $this->assertSame(['label' => 'Woman', 'count' => null, 'percent' => null], $gender['options'][0]);
        $this->assertSame(['label' => 'Man', 'count' => null, 'percent' => null], $gender['options'][1]);
        $this->assertSame(['label' => 'Prefer not to say', 'count' => 0, 'percent' => 0], $gender['options'][2]);
        $this->assertSame(8, $gender['answered'], 'answered is not suppressed');
        $this->assertFalse($enjoyed['suppressed']);
        $this->assertSame(1, $enjoyed['options'][2]['count']);
    }

    /**
     * @return array<string, array{list<int>, list<int|null>}>
     */
    public static function suppressionCases(): array
    {
        return [
            'nothing small'               => [[10, 7, 0], [10, 7, 0]],
            'two small: no extra needed'  => [[10, 3, 2], [10, null, null]],
            'one small: next smallest too' => [[10, 7, 3, 0], [10, null, null, 0]],
            // "Answered by 3" would give it away, so which answer it was is hidden too.
            'the only answer is small'    => [[3, 0, 0], [null, null, null]],
            'only small answers, one each' => [[0, 2, 0], [null, null, null]],
            'all small'                   => [[1, 4, 2], [null, null, null]],
        ];
    }

    /**
     * @param list<int>      $counts
     * @param list<int|null> $shown
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('suppressionCases')]
    public function testOneHiddenCountIsNeverLeftToBeWorkedOutBySubtraction(array $counts, array $shown): void
    {
        $this->assertSame($shown, FormSubmissionAnalyser::suppress($counts));
    }

    public function testAHiddenGridRowAndLikertKeepTheSameProtection(): void
    {
        $analyser = new FormSubmissionAnalyser(self::SHAPES, [], true);
        $responses = [];
        foreach (['0', '0', '0', '0', '0', '0', '2', '2'] as $i => $skill) {
            $responses[] = $this->response('2026-01-01 00:00:00', [
                'skill'  => $skill,
                'rating' => $i < 7 ? 'venue: Good' : 'venue: Poor, sadly',
            ]);
        }

        $result = $analyser->analyse($this->table($responses));
        $skill = $this->find($result, 'c:skill');

        $this->assertSame([null, 0, null, 0, 0], array_column($skill['points'], 'count'));
        $this->assertNull($skill['mean']);
        $this->assertNull($skill['median']);
        $this->assertSame([null, null], $this->find($result, 'c:rating')['rows'][0]['counts']);
    }

    public function testEveryQuestionIsSmallCountSuppressedForTheEdiType(): void
    {
        $analyser = new FormSubmissionAnalyser(self::SHAPES, [], true);

        $result = $analyser->analyse($this->table([$this->response('2026-01-01 00:00:00', ['enjoyed' => 'Yes'])]));

        $this->assertNull($this->find($result, 'c:enjoyed')['options'][0]['count']);
    }

    /**
     * @param list<array<string, string>> $answers
     * @return array<string, mixed>
     */
    private function question(string $id, array $answers): array
    {
        $responses = array_map(fn(array $a): array => $this->response('2026-01-01 00:00:00', $a), $answers);
        return $this->find($this->analyse($responses), $id);
    }

    /**
     * @param list<array{formType: string, title: string, date: string, items: list<array<string, mixed>>}> $responses
     * @return array<string, mixed>
     */
    private function analyse(array $responses): array
    {
        return (new FormSubmissionAnalyser(self::SHAPES))->analyse($this->table($responses));
    }

    /**
     * @param list<array<string, mixed>> $responses
     * @return array<string, mixed>
     */
    private function table(array $responses): array
    {
        $submissions = [];
        foreach ($responses as $i => $response) {
            $submissions[] = ['formType' => 't', 'title' => 'R' . $i, 'date' => $response['date'], 'items' => $response['items']];
        }
        return (new FormSubmissionExporter())->table($submissions);
    }

    /**
     * @param array<string, string> $answers Key => answer
     * @return array{date: string, items: list<array<string, string>>}
     */
    private function response(string $date, array $answers): array
    {
        $items = [];
        foreach ($answers as $key => $answer) {
            $items[] = ['question' => ucfirst($key), 'answer' => $answer, 'key' => $key, 'column' => $key];
        }
        return ['date' => $date, 'items' => $items];
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function find(array $result, string $id): array
    {
        foreach ($result['questions'] as $question) {
            if ($question['id'] === $id) {
                return $question;
            }
        }
        $this->fail('No question ' . $id);
    }
}
