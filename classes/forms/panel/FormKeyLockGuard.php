<?php

declare(strict_types=1);

namespace BSBI\WebBase\forms\panel;

use BSBI\WebBase\forms\FormBuilderOptions;
use Kirby\Cms\App;
use Kirby\Cms\Page;

/**
 * Applies key locking to panel saves of panel-built forms (and hand-written
 * forms' extra sections) and library sections, and lists what is locked for
 * the panel checks.
 *
 * A save is read as the page would be after it (its content with the saved
 * values over it, held in memory) and compared with the page as it is. On a
 * form, its own questions are compared. On a library section, every form
 * using it (directly or through a variation) is compared as it would be with
 * the edited section. Responses are only read when a key would be lost.
 */
final readonly class FormKeyLockGuard
{
    /** Template of library section pages. */
    public const SECTION_TEMPLATE = 'form_section';

    private FormKeyLock $lock;

    /**
     * @param ResponseKeySource   $responses     Reads the keys stored responses use
     * @param SectionPageResolver $resolver      Finds referenced library sections
     * @param FormsUsingSection   $forms         Finds the forms using a section
     * @param array<string, string> $sectionsFields Form template => its blocks field of sections
     *                                              (FormBuilderOptions::sectionsFields())
     */
    public function __construct(
        private ResponseKeySource $responses,
        private SectionPageResolver $resolver,
        private FormsUsingSection $forms,
        private array $sectionsFields = ['form_builder' => 'formSections'],
    ) {
        $this->lock = new FormKeyLock($responses);
    }

    /**
     * Returns a guard reading the site's forms, sections and responses.
     *
     * @param App $kirby
     */
    public static function forKirby(App $kirby): self
    {
        return new self(
            new KirbyResponseKeySource(),
            new KirbySectionPageResolver($kirby),
            new IndexedFormsUsingSection($kirby),
            FormBuilderOptions::sectionsFields($kirby)
        );
    }

    /**
     * Refuses a save that would rename or remove a question whose key stored
     * responses use. Pages that are not forms or sections pass untouched.
     *
     * @param Page                 $page         The page as it is
     * @param array<string, mixed> $strings      The values being saved, as stored strings
     * @param string|null          $languageCode Language being saved (null: current)
     * @throws FormKeyLockedException
     */
    public function check(Page $page, array $strings, ?string $languageCode = null): void
    {
        $template = $page->intendedTemplate()->name();
        if ($template === self::SECTION_TEMPLATE) {
            $changes = $this->sectionChanges($page, self::edited($page, $strings, $languageCode));
        } elseif (isset($this->sectionsFields[$template])) {
            $changes = $this->formChanges($page, self::edited($page, $strings, $languageCode));
        } else {
            return;
        }

        $conflicts = $this->lock->conflicts($changes);
        if ($conflicts !== []) {
            throw new FormKeyLockedException(FormKeyLock::message($conflicts));
        }
    }

    /**
     * Returns the form's questions that its stored responses use, as key =>
     * label, for the Form check.
     *
     * @param Page $form A panel-built form page
     * @return array<string, string>
     */
    public function lockedOnForm(Page $form): array
    {
        return $this->lock->lockedQuestions($form, $this->columns($form, $this->resolver));
    }

    /**
     * Returns the section's questions (inherited ones included) that stored
     * responses to forms using it use, each with those forms' titles, in
     * section order, for the section's "What forms get".
     *
     * @param Page $section A library section page
     * @return list<array{label: string, key: string, forms: list<string>}>
     */
    public function lockedInSection(Page $section): array
    {
        $questions = [];
        foreach ((new PanelSectionReader($this->resolver))->read($section, new FormProblems()) as $field) {
            if ($field->isSubmittable()) {
                $questions[$field->key] ??= $field->label;
            }
        }
        if ($questions === []) {
            return [];
        }

        $usedBy = [];
        foreach ($this->forms->forms($section) as $form) {
            if (!$this->responses->hasResponses($form)) {
                continue;
            }
            foreach ($this->responses->usedKeys($form, array_keys($questions)) as $key) {
                $usedBy[$key][] = PanelContent::text($form->content(), 'title') ?: $form->slug();
            }
        }

        $locked = [];
        foreach ($questions as $key => $label) {
            if (isset($usedBy[$key])) {
                $locked[] = ['label' => $label, 'key' => (string) $key, 'forms' => $usedBy[$key]];
            }
        }
        return $locked;
    }

    /**
     * Returns the change to a form's own questions, if it has responses.
     *
     * @param Page $form   The form as it is
     * @param Page $edited The form as it would be saved
     * @return list<array{form: Page, before: array<string, array{label: string, column: string}>, after: array<string, array{label: string, column: string}>}>
     */
    private function formChanges(Page $form, Page $edited): array
    {
        if (!$this->responses->hasResponses($form)) {
            return [];
        }
        return [[
            'form'   => $form,
            'before' => $this->columns($form, $this->resolver),
            'after'  => $this->columns($edited, $this->resolver),
        ]];
    }

    /**
     * Returns the change to every form with responses that uses the section,
     * when the edit loses any of the section's keys (none otherwise: a key
     * the section keeps is still on every form using it).
     *
     * @param Page $section The section as it is
     * @param Page $edited  The section as it would be saved
     * @return list<array{form: Page, before: array<string, array{label: string, column: string}>, after: array<string, array{label: string, column: string}>}>
     */
    private function sectionChanges(Page $section, Page $edited): array
    {
        $reader = new PanelSectionReader($this->resolver);
        $keysOf = static fn(Page $page): array => array_map(
            static fn(PanelField $field): string => $field->key,
            $reader->read($page, new FormProblems())
        );
        if (FormKeyLock::lostKeys($keysOf($section), $keysOf($edited)) === []) {
            return [];
        }

        $withEdit = new SectionOverrideResolver($this->resolver, $edited);
        $changes = [];
        foreach ($this->forms->forms($section) as $form) {
            if (!$this->responses->hasResponses($form)) {
                continue;
            }
            $changes[] = [
                'form'   => $form,
                'before' => $this->columns($form, $this->resolver),
                'after'  => $this->columns($form, $withEdit),
            ];
        }
        return $changes;
    }

    /**
     * Returns a form's panel questions as stored on submissions, read from
     * its template's sections field.
     *
     * @param Page                $form
     * @param SectionPageResolver $resolver
     * @return array<string, array{label: string, column: string}>
     */
    private function columns(Page $form, SectionPageResolver $resolver): array
    {
        $field = $this->sectionsFields[$form->intendedTemplate()->name()] ?? 'formSections';
        return (new PanelFormDefinition($form, '', $resolver, $field))->getSubmissionColumns();
    }

    /**
     * Returns an in-memory copy of the page with the saved values over its
     * content (in the language being saved), so it reads as it would after
     * the save. Nothing is written.
     *
     * @param Page                 $page
     * @param array<string, mixed> $strings      Values being saved, as stored strings
     * @param string|null          $languageCode Language being saved (null: current)
     */
    public static function edited(Page $page, array $strings, ?string $languageCode = null): Page
    {
        $content = array_merge(
            array_change_key_case($page->content($languageCode)->toArray()),
            array_change_key_case($strings)
        );
        $edited = $page->clone(['content' => $content]);
        // The clone holds the content as the default language; a save in
        // another language must read it there too.
        $edited->version()->save($content, $languageCode ?? 'current', true);
        return $edited;
    }
}
