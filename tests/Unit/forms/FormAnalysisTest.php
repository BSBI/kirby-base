<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\forms;

use BSBI\WebBase\forms\FormAnalysis;
use BSBI\WebBase\Testing\KirbyTestEnvironment;
use Kirby\Cms\App;
use Kirby\Data\Json;
use Kirby\Data\Yaml;
use PHPUnit\Framework\TestCase;

/**
 * Tests for FormAnalysis: a form type's responses gathered, filtered and
 * summarised, with each question's shape from the forms' definitions.
 */
final class FormAnalysisTest extends TestCase
{
    private static App $kirby;

    public static function setUpBeforeClass(): void
    {
        $fixture = sys_get_temp_dir() . '/kirby-base-form-analysis-' . uniqid();
        $sections = Json::encode([
            ['id' => 'aaaaaaaa-0000-4000-8000-000000000001', 'type' => 'form-section-inline', 'content' => [
                'title' => 'Your visit',
                'formFields' => Json::encode([
                    ['id' => 'bbbbbbbb-0000-4000-8000-000000000001', 'type' => 'form-radio-group',
                        'content' => ['label' => 'Did you enjoy it?', 'name' => 'enjoyed', 'options' => "Yes\nNo\nUnsure"]],
                    ['id' => 'bbbbbbbb-0000-4000-8000-000000000002', 'type' => 'form-textarea',
                        'content' => ['label' => 'Comments', 'name' => 'comments']],
                ]),
            ]],
            ['id' => 'aaaaaaaa-0000-4000-8000-000000000002', 'type' => 'form-section-inline', 'content' => [
                'title' => 'Equality',
                'alsoSaveAs' => 'edi',
                'formFields' => Json::encode([
                    ['id' => 'bbbbbbbb-0000-4000-8000-000000000003', 'type' => 'form-radio-group',
                        'content' => ['label' => 'Gender', 'name' => 'gender', 'options' => "Woman\nMan"]],
                ]),
            ]],
        ]);
        $files = [
            'walk/form_builder.txt'  => "Title: Walk feedback\n\n----\n\nForm_type: event_feedback\n\n----\n\nFormsections: " . $sections,
            'talk/form_builder.txt'  => "Title: Talk feedback\n\n----\n\nForm_type: event_feedback\n\n----\n\nFormsections: " . $sections,
        ];
        $responses = [
            'walk/r1' => ['2026-07-01', ['enjoyed' => 'Yes', 'comments' => 'Lovely', 'gender' => 'Woman']],
            'walk/r2' => ['2026-08-01', ['enjoyed' => 'Yes', 'comments' => '', 'gender' => 'Man']],
            'talk/r3' => ['2026-08-15', ['enjoyed' => 'No', 'comments' => 'Too long', 'gender' => 'Woman']],
        ];
        foreach ($files as $path => $text) {
            mkdir(dirname($fixture . '/' . $path), 0777, true);
            file_put_contents($fixture . '/' . $path, $text);
        }
        foreach ($responses as $id => [, $answers]) {
            $items = [];
            foreach ($answers as $key => $answer) {
                $items[] = ['question' => ucfirst($key), 'answer' => $answer, 'key' => $key, 'column' => $key];
            }
            mkdir($fixture . '/' . $id, 0777, true);
            file_put_contents($fixture . '/' . $id . '/form_submission.txt', "Title: " . $id . "\n\n----\n\nForm_type: event_feedback\n\n----\n\nSubmission: \n\n" . Yaml::encode($items));
        }
        file_put_contents($fixture . '/site.txt', 'Title: Test');
        self::$kirby = KirbyTestEnvironment::bootWithContent($fixture, 'kirby-base-form-analysis');

        // Content files get their dates from their modified time.
        foreach ($responses as $id => [$date]) {
            touch((string) self::$kirby->root('content') . '/' . $id . '/form_submission.txt', (int) strtotime($date . ' 12:00:00'));
        }
    }

    public function testQuestionsTakeTheirShapeFromTheFormsDefinitions(): void
    {
        $result = $this->analysis()->forType('event_feedback');

        $this->assertSame(3, $result['total']);
        $enjoyed = $this->question($result, 'c:enjoyed');
        $this->assertFalse($enjoyed['inferred']);
        $this->assertSame('donut', $enjoyed['chart']);
        $this->assertSame([2, 1, 0], array_column($enjoyed['options'], 'count'));
        $this->assertSame('list', $this->question($result, 'c:comments')['chart']);
    }

    public function testAnAlsoSaveAsEdiSectionHasItsSmallCountsHidden(): void
    {
        $result = $this->analysis()->forType('event_feedback');

        $this->assertTrue($this->question($result, 'c:gender')['suppressed']);
        $this->assertFalse($this->question($result, 'c:enjoyed')['suppressed']);
    }

    public function testTheFormsToFilterByAreTheOnesWithResponses(): void
    {
        $result = $this->analysis()->forType('event_feedback');

        $this->assertSame([['id' => 'talk', 'title' => 'Talk feedback'], ['id' => 'walk', 'title' => 'Walk feedback']], $result['forms']);
    }

    public function testFiltersByFormAndByDate(): void
    {
        $this->assertSame(2, $this->analysis()->forType('event_feedback', 'walk')['total']);
        $this->assertSame(2, $this->analysis()->forType('event_feedback', '', '2026-08-01', '2026-08-31')['total']);
        $this->assertSame(1, $this->analysis()->forType('event_feedback', 'walk', '2026-08-01')['total']);
    }

    private function analysis(): FormAnalysis
    {
        return new FormAnalysis(
            self::$kirby,
            static fn(string $type): array => $type === 'event_feedback' ? ['walk/r1', 'walk/r2', 'talk/r3'] : [],
            static fn(): array => ['walk' => 'event_feedback', 'talk' => 'event_feedback'],
        );
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function question(array $result, string $id): array
    {
        foreach ($result['questions'] as $question) {
            if ($question['id'] === $id) {
                return $question;
            }
        }
        $this->fail('No question ' . $id);
    }
}
