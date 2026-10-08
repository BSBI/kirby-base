<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\forms;

use BSBI\WebBase\forms\FormsInUse;
use BSBI\WebBase\helpers\ContentTemplateScanner;
use BSBI\WebBase\Testing\KirbyTestEnvironment;
use Kirby\Cms\App;
use PHPUnit\Framework\TestCase;

/**
 * Tests for FormsInUse: every page that is a form, found by its content file
 * name, with where it is, its status and its responses.
 */
final class FormsInUseTest extends TestCase
{
    private static App $kirby;

    public static function setUpBeforeClass(): void
    {
        $fixture = sys_get_temp_dir() . '/kirby-base-forms-in-use-' . uniqid();
        $files = [
            '1_take-part/take-part.txt'                         => "Title: Take part",
            '1_take-part/2_survey/form_builder.txt'             => "Title: Spring survey\n\n----\n\nForm_type: event_feedback",
            '1_take-part/2_survey/1_r1/form_submission.txt'     => "Title: R1",
            '1_take-part/2_survey/2_r2/form_submission.txt'     => "Title: R2",
            '1_take-part/old-form/form_training.txt'            => "Title: Old training",
            '1_take-part/_drafts/draft-form/form_builder.txt'   => "Title: Draft form",
            'mystery/default.txt'                               => "Title: Mystery page",
            'mystery/1_r/form_submission.txt'                   => "Title: R",
            'about/default.txt'                                 => "Title: Not a form",
            '3_a/a.txt'                                         => "Title: Level A",
            '3_a/1_b/b.txt'                                     => "Title: Level B",
            '3_a/1_b/1_c/form_builder.txt'                      => "Title: Deep form",
        ];
        foreach ($files as $path => $text) {
            if (!is_dir(dirname($fixture . '/' . $path))) {
                mkdir(dirname($fixture . '/' . $path), 0777, true);
            }
            file_put_contents($fixture . '/' . $path, $text);
        }
        file_put_contents($fixture . '/site.txt', 'Title: Test');
        self::$kirby = KirbyTestEnvironment::bootWithContent($fixture, 'kirby-base-forms-in-use');
    }

    public function testTheScannerFindsPagesByContentFileName(): void
    {
        $scanner = new ContentTemplateScanner((string) self::$kirby->root('content'));

        $this->assertSame(
            ['a/b/c', 'take-part/draft-form', 'take-part/old-form', 'take-part/survey'],
            $scanner->pageIds(['form_builder', 'form_training'])
        );
        $this->assertSame('a/b/c', ContentTemplateScanner::idFor('1_a/_drafts/b/20260101_c'));
    }

    public function testEachFormHasWhereItIsItsKindStatusAndResponses(): void
    {
        $rows = $this->rows();

        $this->assertSame(['take-part/survey', 'mystery', 'take-part/draft-form', 'a/b/c', 'take-part/old-form'], array_column($rows, 'id'));
        $survey = $rows[0];
        $this->assertSame('Spring survey', $survey['title']);
        $this->assertSame('Take part', $survey['where']);
        $this->assertSame('Built in the panel', $survey['kind']);
        $this->assertSame('event_feedback', $survey['formType']);
        $this->assertSame('listed', $survey['status']);
        $this->assertSame(2, $survey['responses']);
        $this->assertSame('2026-10-01', $survey['lastResponse']);
        $this->assertSame('1_take-part/2_survey', $survey['folder']);
        $this->assertStringContainsString('/pages/take-part+survey', $survey['panelUrl']);
    }

    public function testAPageWithResponsesButNoKnownFormTemplateIsStillListed(): void
    {
        $mystery = $this->row('mystery');

        $this->assertSame('Other page with responses (default)', $mystery['kind']);
        $this->assertSame(1, $mystery['responses']);
    }

    public function testWhereReadsFromTheTopOfTheSiteDown(): void
    {
        $this->assertSame('Level A › Level B', $this->row('a/b/c')['where']);
    }

    public function testADraftInsideAPublishedPageGetsItsResponses(): void
    {
        $this->assertSame(3, $this->row('take-part/draft-form')['responses']);
    }

    public function testDraftsAndFormsWithoutResponsesAreListed(): void
    {
        $this->assertSame('draft', $this->row('take-part/draft-form')['status'], 'a draft inside a published page');
        $this->assertSame('unlisted', $this->row('take-part/old-form')['status']);
        $this->assertSame(0, $this->row('take-part/old-form')['responses']);
        $this->assertSame('', $this->row('take-part/old-form')['lastResponse']);
        $this->assertSame('Hand-written', $this->row('take-part/old-form')['kind']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        $forms = new FormsInUse(
            self::$kirby,
            ['form_builder' => 'Built in the panel', 'form_training' => 'Hand-written'],
            static fn(): array => [
                'take-part/survey' => ['count' => 2, 'last' => '2026-10-01 09:00:00'],
                'mystery'          => ['count' => 1, 'last' => '2026-09-01 09:00:00'],
                // A response's form is its parent's id, which leaves out _drafts.
                'take-part/draft-form' => ['count' => 3, 'last' => '2026-08-01 09:00:00'],
            ],
        );
        return $forms->rows();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $id): array
    {
        foreach ($this->rows() as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }
        $this->fail('No row for ' . $id);
    }
}
