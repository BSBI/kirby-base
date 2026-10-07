<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\forms\panel;

use BSBI\WebBase\forms\panel\FormKeyLock;
use BSBI\WebBase\Testing\KirbyContentBuilder;
use BSBI\WebBase\Testing\KirbyTestEnvironment;
use Kirby\Cms\Page;
use PHPUnit\Framework\TestCase;

/**
 * Tests for FormKeyLock: which keys a save may not lose, and the message
 * refusing it.
 */
final class FormKeyLockTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        KirbyTestEnvironment::boot('kirby-base-form-key-lock-' . uniqid());
    }

    public function testLostKeysAreThoseMissingAfter(): void
    {
        $this->assertSame(['b'], FormKeyLock::lostKeys(['a', 'b', 'c'], ['c', 'a', 'd']));
        $this->assertSame([], FormKeyLock::lostKeys(['a'], ['a', 'b']));
    }

    public function testALostKeyThatResponsesUseConflicts(): void
    {
        $form = $this->form('survey', 'Spring survey');
        $lock = new FormKeyLock(new FakeResponseKeySource(['survey' => ['seen', 'name']]));

        $conflicts = $lock->conflicts([[
            'form'   => $form,
            'before' => $this->columns(['seen' => 'Where?', 'name' => 'Name']),
            'after'  => $this->columns(['seen_where' => 'Where?', 'name' => 'Name']),
        ]]);

        $this->assertSame(['seen' => ['label' => 'Where?', 'forms' => ['Spring survey']]], $conflicts);
    }

    public function testALostKeyNoResponseUsesIsFree(): void
    {
        $form = $this->form('survey', 'Spring survey');
        $lock = new FormKeyLock(new FakeResponseKeySource(['survey' => ['name']]));

        $conflicts = $lock->conflicts([[
            'form'   => $form,
            'before' => $this->columns(['name' => 'Name', 'added_later' => 'New']),
            'after'  => $this->columns(['name' => 'Name']),
        ]]);

        $this->assertSame([], $conflicts);
    }

    public function testResponsesAreNotReadWhenNoKeyIsLost(): void
    {
        $responses = new FakeResponseKeySource(['survey' => ['name']]);
        $lock = new FormKeyLock($responses);

        $conflicts = $lock->conflicts([[
            'form'   => $this->form('survey', 'Spring survey'),
            'before' => $this->columns(['name' => 'Name']),
            'after'  => $this->columns(['name' => 'Your name', 'extra' => 'Extra']),
        ]]);

        $this->assertSame([], $conflicts);
        $this->assertSame([], $responses->read);
    }

    public function testAKeyLostOnSeveralFormsNamesEach(): void
    {
        $lock = new FormKeyLock(new FakeResponseKeySource(['a' => ['k'], 'b' => ['k']]));
        $change = fn(Page $form): array => [
            'form'   => $form,
            'before' => $this->columns(['k' => 'Question']),
            'after'  => [],
        ];

        $conflicts = $lock->conflicts([$change($this->form('a', 'Form A')), $change($this->form('b', 'Form B'))]);

        $this->assertSame(['k' => ['label' => 'Question', 'forms' => ['Form A', 'Form B']]], $conflicts);
    }

    public function testLockedQuestionsAreThoseResponsesUse(): void
    {
        $form = $this->form('survey', 'Spring survey');
        $lock = new FormKeyLock(new FakeResponseKeySource(['survey' => ['name', 'seen']]));

        $this->assertSame(
            ['seen' => 'Where?', 'name' => 'Name'],
            $lock->lockedQuestions($form, $this->columns(['seen' => 'Where?', 'new' => 'New', 'name' => 'Name']))
        );
        $this->assertSame([], (new FormKeyLock(new FakeResponseKeySource([])))
            ->lockedQuestions($form, $this->columns(['seen' => 'Where?'])));
    }

    public function testTheMessageForOneQuestion(): void
    {
        $message = FormKeyLock::message(['seen' => ['label' => 'Where?', 'forms' => ['Spring survey']]]);

        $this->assertSame(
            'This can\'t be saved: "Where?" (field name "seen") is used by responses to "Spring survey", so it '
            . 'can\'t be renamed or removed. Undo that change, then save. You can still change its label, help '
            . 'text and options.',
            $message
        );
    }

    public function testTheMessageForSeveralQuestionsIsShortened(): void
    {
        $conflicts = [];
        foreach (range(1, 7) as $n) {
            $conflicts['k' . $n] = ['label' => $n === 2 ? '' : 'Q' . $n, 'forms' => ['F']];
        }
        $conflicts['k1']['forms'] = ['F1', 'F2', 'F3', 'F4', 'F5', 'F6', 'F7'];

        $message = FormKeyLock::message($conflicts);

        $this->assertStringContainsString('these questions are used by stored responses', $message);
        $this->assertStringContainsString(
            '"Q1" (field name "k1"), used by responses to "F1", "F2", "F3", "F4", "F5" and 2 other forms; '
            . 'field name "k2", used by responses to "F"; ',
            $message
        );
        $this->assertStringContainsString('"Q5" (field name "k5"), used by responses to "F"; and 2 more questions.', $message);
        $this->assertStringNotContainsString('k6', $message);
    }

    /**
     * @param array<string, string> $labels Key => label
     * @return array<string, array{label: string, column: string}>
     */
    private function columns(array $labels): array
    {
        $columns = [];
        foreach ($labels as $key => $label) {
            $columns[$key] = ['label' => $label, 'column' => $key];
        }
        return $columns;
    }

    private function form(string $slug, string $title): Page
    {
        return (new KirbyContentBuilder())->page(['title' => $title], $slug);
    }
}
