<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\forms;

use BSBI\WebBase\forms\FormSubmissionWriter;
use BSBI\WebBase\forms\SessionSlots;
use BSBI\WebBase\Testing\KirbyTestEnvironment;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use PHPUnit\Framework\TestCase;

/**
 * Tests for FormSubmissionWriter: saving a response as a form_submission page,
 * or updating the one saved earlier in the same session.
 */
final class FormSubmissionWriterTest extends TestCase
{
    private static App $app;

    public static function setUpBeforeClass(): void
    {
        self::$app = KirbyTestEnvironment::boot('kirby-base-submission-writer-' . uniqid(), [
            'blueprints' => ['pages/form_submission' => ['title' => 'Submission']],
        ]);
    }

    protected function setUp(): void
    {
        self::$app->impersonate('kirby');
    }

    public function testWithoutSessionSlotsEverySaveIsANewListedResponse(): void
    {
        $form = $this->form();
        $writer = new FormSubmissionWriter();

        $first = $writer->write($form, 'feedback', [['question' => 'Q', 'answer' => 'one']]);
        $second = $writer->write($form, 'feedback', [['question' => 'Q', 'answer' => 'two']]);

        $this->assertNotSame($first->id(), $second->id());
        $this->assertCount(2, $this->reload($form)->children()->listed());
        $this->assertSame('feedback', $first->content()->get('form_type')->value());
        $this->assertSame('form_submission', $first->intendedTemplate()->name());
    }

    public function testWithSessionSlotsTheSecondSaveUpdatesTheFirst(): void
    {
        $form = $this->form();
        $writer = new FormSubmissionWriter($this->slots());

        $first = $writer->write($form, 'feedback', [['question' => 'Q', 'answer' => 'one']]);
        $second = $writer->write($form, 'feedback', [['question' => 'Q', 'answer' => 'two']]);

        $this->assertSame($first->id(), $second->id());
        $this->assertCount(1, $this->reload($form)->children());
        $this->assertSame('two', $this->reload($form)->children()->first()?->content()->get('submission')->yaml()[0]['answer']);
    }

    public function testEachFormTypeAndPageHasItsOwnSlot(): void
    {
        $form = $this->form();
        $other = $this->form();
        $writer = new FormSubmissionWriter($this->slots());

        $main = $writer->write($form, 'feedback', [['question' => 'Q', 'answer' => 'a']]);
        $copy = $writer->write($form, 'edi', [['question' => 'Gender', 'answer' => 'b']]);
        $elsewhere = $writer->write($other, 'feedback', [['question' => 'Q', 'answer' => 'c']]);

        $this->assertNotSame($main->id(), $copy->id());
        $this->assertCount(2, $this->reload($form)->children());
        $this->assertCount(1, $this->reload($other)->children());
        $this->assertSame($other->id(), $elsewhere->parent()?->id());
    }

    public function testASlotWhoseResponseIsGoneStartsAFreshOne(): void
    {
        $form = $this->form();
        $slots = $this->slots();
        $writer = new FormSubmissionWriter($slots);

        $first = $writer->write($form, 'feedback', [['question' => 'Q', 'answer' => 'one']]);
        $first->delete();
        $second = $writer->write($form, 'feedback', [['question' => 'Q', 'answer' => 'two']]);

        $this->assertNotSame($first->id(), '');
        $this->assertCount(1, $this->reload($form)->children());
        $this->assertSame('two', $second->content()->get('submission')->yaml()[0]['answer']);
    }

    private function form(): Page
    {
        return self::$app->site()->createChild([
            'slug'    => 'form-' . uniqid(),
            'template' => 'form_builder',
            'draft'   => false,
            'content' => ['title' => 'A form'],
        ]);
    }

    private function reload(Page $page): Page
    {
        $fresh = self::$app->site()->findPageOrDraft($page->id());
        $this->assertInstanceOf(Page::class, $fresh);
        return $fresh;
    }

    private function slots(): SessionSlots
    {
        return new class implements SessionSlots {
            /** @var array<string, string> */
            private array $slots = [];

            public function get(string $name): ?string
            {
                return $this->slots[$name] ?? null;
            }

            public function set(string $name, string $value): void
            {
                $this->slots[$name] = $value;
            }
        };
    }
}
