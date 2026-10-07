<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\forms\panel;

use BSBI\WebBase\forms\panel\FormKeyLockedException;
use BSBI\WebBase\forms\panel\FormKeyLockGuard;
use BSBI\WebBase\forms\panel\FormsUsingSection;
use BSBI\WebBase\Testing\KirbyTestEnvironment;
use Kirby\Cms\Page;
use Kirby\Data\Json;
use PHPUnit\Framework\TestCase;

/**
 * Tests for FormKeyLockGuard: panel saves of forms and library sections that
 * would rename or remove a question stored responses use are refused.
 */
final class FormKeyLockGuardTest extends TestCase
{
    use PanelFormFixtures;

    private const QUESTION_ID = 'aaaaaaaa-0000-4000-8000-000000000001';

    public static function setUpBeforeClass(): void
    {
        KirbyTestEnvironment::boot('kirby-base-form-key-lock-guard-' . uniqid());
    }

    public function testRenamingAQuestionResponsesUseIsRefused(): void
    {
        $form = $this->builtForm([$this->inline([$this->textbox('Where?', 'seen')])]);
        $guard = $this->guard(['survey' => ['seen']]);

        $this->expectException(FormKeyLockedException::class);
        $this->expectExceptionMessage('"Where?" (field name "seen") is used by responses to "Spring survey"');

        $guard->check($form, $this->saving([$this->inline([$this->textbox('Where?', 'seen_where')])]));
    }

    public function testRelabellingAndAddingQuestionsIsSaved(): void
    {
        $form = $this->builtForm([$this->inline([$this->textbox('Where?', 'seen')])]);

        $this->guard(['survey' => ['seen']])->check($form, $this->saving([$this->inline([
            $this->textbox('Where did you see it?', 'seen'),
            $this->textbox('Anything else?', 'else'),
        ])]));

        $this->addToAssertionCount(1);
    }

    public function testDeletingAndReAddingAnUnnamedQuestionIsRefused(): void
    {
        $form = $this->builtForm([$this->inline([$this->textbox('Where?', '', self::QUESTION_ID)])]);

        $this->expectException(FormKeyLockedException::class);

        $this->guard(['survey' => ['f_aaaaaaaa']])
            ->check($form, $this->saving([$this->inline([$this->textbox('Where?', '')])]));
    }

    public function testRemovingASectionResponsesUseIsRefused(): void
    {
        $form = $this->builtForm([
            $this->inline([$this->textbox('Name', 'name')]),
            $this->inline([$this->textbox('Where?', 'seen')]),
        ]);

        $this->expectException(FormKeyLockedException::class);

        $this->guard(['survey' => ['name', 'seen']])
            ->check($form, $this->saving([$this->inline([$this->textbox('Name', 'name')])]));
    }

    public function testRemovingAQuestionNoResponseUsesIsSaved(): void
    {
        $form = $this->builtForm([$this->inline([$this->textbox('Name', 'name'), $this->textbox('Typo', 'typo')])]);

        $this->guard(['survey' => ['name']])
            ->check($form, $this->saving([$this->inline([$this->textbox('Name', 'name')])]));

        $this->addToAssertionCount(1);
    }

    public function testAFormWithoutResponsesIsNotLocked(): void
    {
        $form = $this->builtForm([$this->inline([$this->textbox('Where?', 'seen')])]);

        $this->guard([])->check($form, $this->saving([]));

        $this->addToAssertionCount(1);
    }

    public function testOtherPagesAreNotChecked(): void
    {
        $page = Page::factory(['slug' => 'other', 'template' => 'default', 'content' => ['title' => 'Other']]);

        $this->guard(['other' => ['x']])->check($page, ['title' => 'Changed']);

        $this->addToAssertionCount(1);
    }

    public function testCheckingASaveWritesNothing(): void
    {
        $form = $this->builtForm([$this->inline([$this->textbox('Where?', 'seen')])]);
        $before = $form->content()->get('formsections')->value();

        $this->guard([])->check($form, $this->saving([]));

        $this->assertSame($before, $form->content()->get('formsections')->value());
    }

    public function testTheEditedCopyReadsTheSavedValues(): void
    {
        $form = $this->builtForm([]);

        $edited = FormKeyLockGuard::edited($form, ['formSections' => 'saved']);

        $this->assertSame('saved', $edited->content()->get('formsections')->value());
        $this->assertSame('Spring survey', $edited->content()->get('title')->value());
    }

    public function testRenamingAKeyInALibrarySectionAFormsResponsesUseIsRefused(): void
    {
        $section = $this->section('contact', [$this->textbox('Email', 'email')]);
        $form = $this->builtForm([$this->sectionRef('page://contact')]);

        $this->expectException(FormKeyLockedException::class);
        $this->expectExceptionMessage('"Email" (field name "email") is used by responses to "Spring survey"');

        $this->guard(['survey' => ['email']], ['page://contact' => $section], [$form])
            ->check($section, ['formFields' => Json::encode([$this->textbox('Email', 'email_address')])]);
    }

    public function testEditingTheBaseOfAVariationAFormUsesIsChecked(): void
    {
        $base = $this->section('base', [$this->textbox('Email', 'email')]);
        $variation = $this->section('variation', [$this->textbox('Phone', 'phone')], 'page://base');
        $form = $this->builtForm([$this->sectionRef('page://variation')]);
        $guard = $this->guard(
            ['survey' => ['email', 'phone']],
            ['page://base' => $base, 'page://variation' => $variation],
            [$form]
        );

        $this->expectException(FormKeyLockedException::class);

        $guard->check($base, ['formFields' => Json::encode([])]);
    }

    public function testASectionEditKeepingItsKeysDoesNotLookForForms(): void
    {
        $section = $this->section('contact', [$this->textbox('Email', 'email')]);
        $finder = new class implements FormsUsingSection {
            public int $calls = 0;

            public function forms(Page $section): array
            {
                $this->calls++;
                return [];
            }
        };
        $guard = new FormKeyLockGuard(new FakeResponseKeySource([]), $this->resolver([]), $finder);

        $guard->check($section, ['formFields' => Json::encode([$this->textbox('Email address', 'email')])]);

        $this->assertSame(0, $finder->calls);
    }

    public function testASectionKeyNoFormsResponsesUseIsFree(): void
    {
        $section = $this->section('contact', [$this->textbox('Email', 'email')]);
        $form = $this->builtForm([$this->sectionRef('page://contact')]);

        $this->guard(['survey' => ['other']], ['page://contact' => $section], [$form])
            ->check($section, ['formFields' => Json::encode([])]);

        $this->addToAssertionCount(1);
    }

    public function testLockedOnFormListsQuestionsResponsesUse(): void
    {
        $form = $this->builtForm([$this->inline([$this->textbox('Name', 'name'), $this->textbox('New', 'new')])]);

        $this->assertSame(['name' => 'Name'], $this->guard(['survey' => ['name']])->lockedOnForm($form));
    }

    public function testLockedInSectionNamesTheFormsLockingEachQuestion(): void
    {
        $section = $this->section('contact', [$this->textbox('Email', 'email'), $this->textbox('Phone', 'phone')]);
        $form = $this->builtForm([$this->sectionRef('page://contact')]);

        $locked = $this->guard(['survey' => ['phone']], ['page://contact' => $section], [$form])
            ->lockedInSection($section);

        $this->assertSame([['label' => 'Phone', 'key' => 'phone', 'forms' => ['Spring survey']]], $locked);
    }

    /**
     * @param array<string, list<string>> $responses Form id => keys its responses use
     * @param array<string, Page>          $sections  Reference => section page
     * @param list<Page>                   $forms     Forms using any section
     */
    private function guard(array $responses, array $sections = [], array $forms = []): FormKeyLockGuard
    {
        $finder = new class ($forms) implements FormsUsingSection {
            /** @param list<Page> $forms */
            public function __construct(private readonly array $forms)
            {
            }

            public function forms(Page $section): array
            {
                return $this->forms;
            }
        };
        return new FormKeyLockGuard(new FakeResponseKeySource($responses), $this->resolver($sections), $finder);
    }

    /**
     * @param array<int, array<string, mixed>> $sections Raw section block data
     */
    private function builtForm(array $sections): Page
    {
        return Page::factory([
            'slug'     => 'survey',
            'template' => 'form_builder',
            'content'  => ['title' => 'Spring survey', 'formSections' => Json::encode($sections)],
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $fields Raw field block data
     */
    private function section(string $slug, array $fields, ?string $extends = null): Page
    {
        $content = ['title' => ucfirst($slug), 'formFields' => Json::encode($fields)];
        if ($extends !== null) {
            $content['extends'] = '- ' . $extends;
        }
        return Page::factory(['slug' => $slug, 'template' => 'form_section', 'content' => $content]);
    }

    /**
     * Returns the values a panel save of the form's sections sends.
     *
     * @param array<int, array<string, mixed>> $sections Raw section block data
     * @return array<string, string>
     */
    private function saving(array $sections): array
    {
        return ['formSections' => Json::encode($sections)];
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @return array<string, mixed>
     */
    private function inline(array $fields): array
    {
        return $this->sectionInline($fields, 'Section');
    }

    /**
     * @return array<string, mixed>
     */
    private function textbox(string $label, string $name, ?string $id = null): array
    {
        return $this->blockData('form-textbox', ['label' => $label, 'name' => $name], $id);
    }
}
