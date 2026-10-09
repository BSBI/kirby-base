<?php

declare(strict_types=1);

namespace BSBI\WebBase\forms\panel;

use BSBI\WebBase\forms\BaseFormDefinition;
use BSBI\WebBase\forms\FormBuilderOptions;
use BSBI\WebBase\forms\FormFieldSpec;
use BSBI\WebBase\forms\FormSection;
use Kirby\Cms\Block;
use Kirby\Cms\Page;
use Kirby\Content\Content;

/**
 * A form definition read from panel content instead of written in PHP.
 *
 * The form page holds a blocks field (default `formSections`) of:
 *  - `form-section-ref`: a library section chosen in the `section` pages field,
 *    with an optional `title` overriding the section's `legend`
 *  - `form-section-inline`: a section defined on the form itself, with a
 *    `title` and its own `formFields` blocks
 * Either may set `showWhen` (`key:answer`, picked from conditionChoices()) to
 * show the section only when an earlier radio or dropdown field has that
 * answer. Content saved before that field existed uses `showWhenField` (a
 * field key) and `showWhenValue` instead, which are still read.
 *
 * Everything downstream (resolving, rendering, submission handling) is the
 * same as for a hand-written definition. Bad content never stops the form
 * rendering: the offending piece is left out and described by validate().
 */
class PanelFormDefinition extends BaseFormDefinition
{
    /** @var array<FormSection>|null Built on first use */
    private ?array $sections = null;

    /** @var array<string, array{label: string, column: string}> Filled by build() */
    private array $columns = [];

    /** @var array<string, string> Filled by build() */
    private array $conditionChoices = [];

    /** @var list<array{type: string, keys: list<string>, condition: array{0: string, 1: string}|null}> Filled by build() */
    private array $copySections = [];

    /** @var array<string, string> Library questions on the form, key => "Label (Section)", filled by build() */
    private array $leaveOutChoices = [];

    /** @var array<string, true> Names chosen to leave out that their section doesn't have, filled by build() */
    private array $staleLeaveOuts = [];

    /** @var array<string, true> Questions left out on this form, filled by build() */
    private array $leftOut = [];

    /** @var array<string, true> Library section ids used, filled by build() */
    private array $sectionIds = [];

    private FormProblems $problems;

    /**
     * @param Page                $page          The form page holding the section blocks
     * @param string              $formType      Stored on every submission (see getFormType())
     * @param SectionPageResolver $resolver      Finds referenced library sections
     * @param string              $sectionsField Name of the blocks field holding the sections
     * @param list<string>        $reservedKeys  Keys the form already uses for fixed questions
     *                                           (extra sections on a hand-written form); a panel
     *                                           question with one of these keys is left out
     */
    public function __construct(
        private readonly Page $page,
        private readonly string $formType,
        private readonly SectionPageResolver $resolver,
        private readonly string $sectionsField = 'formSections',
        private readonly array $reservedKeys = [],
    ) {
        $this->problems = new FormProblems();
    }

    /**
     * Returns the form-type identifier stored on submissions.
     */
    public function getFormType(): string
    {
        return $this->formType;
    }

    /**
     * Returns editor-facing descriptions of everything that had to be left out
     * of the form, or an empty array if the form is sound.
     *
     * @return string[]
     */
    public function validate(): array
    {
        $this->build();
        return $this->problems->all();
    }

    /**
     * Returns each question's raw label and export column (its "Report as"
     * name, or else its key), keyed by POST key, for the fields kept on the form.
     *
     * @return array<string, array{label: string, column: string}>
     */
    public function getSubmissionColumns(): array
    {
        $this->build();
        return $this->columns;
    }

    /**
     * Returns the choices for a section's "Only show this section when…"
     * select: one per answer of every radio and dropdown question kept on the
     * form, in form order, as `key:answer` => "Question: Answer (Section)".
     * Keys never contain ":", so the stored value splits at the first one.
     * Labels and answers are raw (the panel escapes option text).
     *
     * @return array<string, string>
     */
    public function conditionChoices(): array
    {
        $this->build();
        return $this->conditionChoices;
    }

    /**
     * Returns the separate responses this submission also makes: for each
     * section marked "also save as" that was shown (its condition met) and
     * has at least one answer, its form type => its question keys. Sections
     * with the same type share one response.
     *
     * @param array<mixed> $postData
     * @return array<string, list<string>>
     */
    public function separateCopies(array $postData): array
    {
        $this->build();

        $copies = [];
        foreach ($this->copySections as $section) {
            if ($section['condition'] !== null) {
                $answer = $postData[$section['condition'][0]] ?? null;
                if (!is_scalar($answer) || (string) $answer !== $section['condition'][1]) {
                    continue;
                }
            }
            $answered = array_filter(
                $section['keys'],
                static fn(string $key): bool => !in_array($postData[$key] ?? null, [null, '', []], true)
                    && !(is_string($postData[$key]) && trim($postData[$key]) === '')
            );
            if ($answered !== []) {
                $copies[$section['type']] = array_values(array_unique(array_merge($copies[$section['type']] ?? [], $section['keys'])));
            }
        }
        return $copies;
    }

    /**
     * Returns the sections marked "also save as": form type => their question
     * keys, whether or not a submission shows them.
     *
     * @return array<string, list<string>>
     */
    public function copySectionKeys(): array
    {
        $this->build();
        $keys = [];
        foreach ($this->copySections as $section) {
            $keys[$section['type']] = array_values(array_unique(array_merge($keys[$section['type']] ?? [], $section['keys'])));
        }
        return $keys;
    }

    /**
     * Returns the choices for a library section's "Leave out these questions":
     * every question of the library sections on the form, key => "Label
     * (Section)", plus any chosen key its section no longer has (marked), so
     * the multiselect's values are always among its options.
     *
     * @return array<string, string>
     */
    public function leaveOutChoices(): array
    {
        $this->build();
        return $this->leaveOutChoices;
    }

    /**
     * Returns the ids of the library sections the form uses, including every
     * base section their variations extend, in first-use order.
     *
     * @return list<string>
     */
    public function sectionIds(): array
    {
        $this->build();
        return array_keys($this->sectionIds);
    }

    /**
     * Returns the form's sections, built from the panel content.
     *
     * @return array<FormSection>
     */
    protected function defineForm(): array
    {
        return $this->build();
    }

    /**
     * Reads the panel content once and caches the result.
     *
     * @return array<FormSection>
     */
    private function build(): array
    {
        if ($this->sections !== null) {
            return $this->sections;
        }

        $sectionReader = new PanelSectionReader($this->resolver);
        $blocks = PanelContent::blocks(
            $this->page->content(),
            $this->sectionsField,
            $this->problems,
            $this->page->id(),
            'This form'
        );

        // Pass 1: read every section's fields, so conditions can tell a field
        // that comes later from one that does not exist at all.
        $read = [];
        $position = 0;
        foreach ($blocks as $block) {
            $position++;
            $section = $this->readSection($block, $position, $sectionReader);
            if ($section !== null) {
                $read[] = $section;
            }
        }

        $allKeys = [];
        foreach ($read as $section) {
            foreach ($section['fields'] as $field) {
                $allKeys[$field->key] = true;
            }
        }

        // Pass 2: drop bad and duplicate keys, then attach checked conditions.
        $seen = [];
        $this->sections = [];
        foreach ($read as $section) {
            $specs = [];
            $earlier = $seen;
            $sectionLabel = $section['legend'] !== '' ? $section['legend'] : sprintf('Section %d', $section['position']);
            foreach ($section['fields'] as $field) {
                if (!$this->keyIsUsable($field, $section['name'], $seen)) {
                    continue;
                }
                $seen[$field->key] = $field;
                $specs[] = $field->spec;
                if ($field->canControlConditions()) {
                    foreach ($field->options as $option) {
                        $this->conditionChoices[$field->key . ':' . $option]
                            = $field->label . ': ' . $option . ' (' . $sectionLabel . ')';
                    }
                }
                if ($field->isSubmittable()) {
                    $this->columns[$field->key] = [
                        'label'  => $field->label,
                        'column' => $field->reportAs !== '' ? $field->reportAs : $field->key,
                    ];
                }
            }

            $formSection = FormSection::make($section['id'], $section['legend'])->fields(...$specs);
            $condition = $this->checkedCondition($section['block'], $section['name'], $earlier, $allKeys);
            if ($condition !== null) {
                $formSection->showWhen($condition[0], $condition[1]);
            }
            $copyType = FormBuilderOptions::normaliseFormType(PanelContent::text($section['block']->content(), 'alsoSaveAs'));
            if ($copyType !== '') {
                $keys = [];
                foreach ($section['fields'] as $field) {
                    if (isset($seen[$field->key]) && $seen[$field->key] === $field && $field->isSubmittable()) {
                        $keys[] = $field->key;
                    }
                }
                $this->copySections[] = ['type' => $copyType, 'keys' => $keys, 'condition' => $condition];
            }
            $this->sections[] = $formSection;
        }

        // A stored choice that no longer matches stays choosable (and marked),
        // so the select's value is always one of its options.
        foreach ($blocks as $block) {
            $choice = PanelContent::text($block->content(), 'showWhen');
            if ($choice !== '' && !isset($this->conditionChoices[$choice])) {
                $this->conditionChoices[$choice] = 'No longer on this form: ' . $choice;
            }
        }
        // Likewise for left-out names their section no longer has.
        foreach (array_keys($this->staleLeaveOuts) as $key) {
            $this->leaveOutChoices[(string) $key] ??= 'No longer in its section: ' . $key;
        }

        return $this->sections;
    }

    /**
     * Reads one section block, or returns null (with a problem) if it cannot be.
     *
     * @param Block              $block
     * @param int                $position      1-based position on the form
     * @param PanelSectionReader $sectionReader
     * @return array{id: string, legend: string, name: string, position: int, block: Block, fields: PanelField[]}|null
     */
    private function readSection(Block $block, int $position, PanelSectionReader $sectionReader): ?array
    {
        $content = $block->content();
        $title   = PanelContent::text($content, 'title');

        if ($block->type() === 'form-section-inline') {
            $name = $title !== '' ? sprintf('Section "%s"', $title) : sprintf('Section %d', $position);
            return [
                'id'     => $block->id(),
                'legend'   => $title,
                'name'     => $name,
                'position' => $position,
                'block'    => $block,
                'fields' => $sectionReader->readBlocks(
                    PanelContent::blocks($content, 'formFields', $this->problems, $block->id(), $name),
                    $name,
                    $this->problems
                ),
            ];
        }

        if ($block->type() === 'form-section-ref') {
            $reference = PanelContent::firstReference($content, 'section');
            if ($reference === null) {
                $this->problems->add(sprintf('Section %d has no section chosen; it has been left out.', $position));
                return null;
            }
            $page = $this->resolver->resolve($reference);
            if ($page === null) {
                $this->problems->add(sprintf(
                    'Section %d uses a library section that cannot be found (it may have been deleted); '
                    . 'it has been left out.',
                    $position
                ));
                return null;
            }
            foreach ($sectionReader->chainIds($page) as $sectionId) {
                $this->sectionIds[$sectionId] = true;
            }
            $legend = $title !== '' ? $title : PanelContent::text($page->content(), 'legend');
            $pageTitle = PanelContent::text($page->content(), 'title');
            $name = sprintf('Section "%s"', $pageTitle !== '' ? $pageTitle : $page->slug());
            $sectionLabel = $legend !== '' ? $legend : ($pageTitle !== '' ? $pageTitle : $page->slug());
            return [
                'id'     => $block->id(),
                'legend'   => $legend,
                'name'     => $name,
                'position' => $position,
                'block'    => $block,
                'fields' => $this->leavingOut($this->adjusted($page, $content, $name, $sectionReader), $content, $name, $sectionLabel),
            ];
        }

        $this->problems->add(sprintf(
            'Section %d is a "%s" block, which is not a form section; it has been left out.',
            $position,
            $block->type()
        ));
        return null;
    }

    /**
     * Returns a section block's [field key, answer] condition as stored: the
     * `showWhen` choice (`key:answer`, split at the first ":") when set,
     * otherwise the older `showWhenField` / `showWhenValue` pair.
     *
     * @param Block $block A section block
     * @return array{0: string, 1: string, 2: bool} Field key, answer, and whether it is the old pair
     */
    private static function conditionOf(Block $block): array
    {
        $choice = PanelContent::text($block->content(), 'showWhen');
        if ($choice !== '') {
            $parts = explode(':', $choice, 2);
            return [trim($parts[0]), trim($parts[1] ?? ''), false];
        }

        $field = PanelContent::text($block->content(), 'showWhenField');
        $value = PanelContent::text($block->content(), 'showWhenValue');
        return [$field, $value, $field !== '' || $value !== ''];
    }

    /**
     * Returns true if the field's key can be used: valid, and not already
     * taken by an earlier field. Records a problem otherwise.
     *
     * @param PanelField                $field
     * @param string                    $sectionName
     * @param array<string, PanelField> $seen Fields already on the form, by key
     */
    private function keyIsUsable(PanelField $field, string $sectionName, array $seen): bool
    {
        if (!PanelFieldReader::isValidKey($field->key)) {
            $this->problems->add(sprintf(
                '%s: the field name "%s" is not allowed (use letters, digits and underscores, '
                . 'starting with a letter); the field has been left out.',
                $sectionName,
                $field->key
            ));
            return false;
        }

        if (in_array($field->key, $this->reservedKeys, true)) {
            $this->problems->add(sprintf(
                '%s: the field name "%s" is a question this form always asks, so it has been left out; '
                . 'give it a different field name.',
                $sectionName,
                $field->key
            ));
            return false;
        }

        if (isset($seen[$field->key])) {
            $this->problems->add(sprintf(
                '%s: the field name "%s" is used more than once on this form; only the first is kept.',
                $sectionName,
                $field->key
            ));
            return false;
        }

        return true;
    }

    /**
     * Returns a library section's fields, read with this form's adjustments to
     * them ("Adjust questions on this form", `adjust`: rows of question key,
     * label, options, help; a blank keeps the library's). A question keeps its
     * place, key and everything else. An adjustment for a question the section
     * doesn't have, or options for one without options, is reported.
     *
     * @return PanelField[]
     */
    private function adjusted(Page $page, Content $content, string $sectionName, PanelSectionReader $sectionReader): array
    {
        $adjustments = [];
        foreach (PanelContent::field($content, 'adjust')->yaml() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $text = static fn(string $name): string => is_scalar($row[$name] ?? null) ? trim((string) $row[$name]) : '';
            $key = $text('question');
            if ($key !== '') {
                $adjustments[$key] = ['label' => $text('label'), 'options' => $text('options'), 'help' => $text('help')];
            }
        }
        if ($adjustments === []) {
            return $sectionReader->read($page, $this->problems);
        }

        $fields = (new PanelSectionReader($this->resolver, new PanelFieldReader($adjustments)))->read($page, $this->problems);
        $byKey = [];
        foreach ($fields as $field) {
            $byKey[$field->key] = $field;
        }
        foreach ($adjustments as $key => $adjustment) {
            $field = $byKey[$key] ?? null;
            if ($field === null) {
                $this->problems->add(sprintf(
                    '%s: "%s" is set to be adjusted on this form, but isn\'t one of its questions; the adjustment is ignored.',
                    $sectionName,
                    $key
                ));
            } elseif ($adjustment['options'] !== '' && !$field->canControlConditions() && $field->type !== FormFieldSpec::TYPE_CHECKBOX_GROUP) {
                $this->problems->add(sprintf(
                    '%s: "%s" has adjusted options, but only choice questions have options; they are ignored.',
                    $sectionName,
                    $key
                ));
            }
        }
        return $fields;
    }

    /**
     * Returns a library section's fields without the ones its block leaves out
     * on this form ("Leave out these questions", `leaveOut`: field names), and
     * records every question it has as a leave-out choice. A name that isn't
     * one of the section's questions is reported and otherwise ignored.
     *
     * @param PanelField[] $fields
     * @return PanelField[]
     */
    private function leavingOut(array $fields, Content $content, string $sectionName, string $sectionLabel): array
    {
        foreach ($fields as $field) {
            if ($field->isSubmittable() && PanelFieldReader::isValidKey($field->key)) {
                $this->leaveOutChoices[$field->key] ??= $field->label . ' (' . $sectionLabel . ')';
            }
        }

        $leaveOut = array_values(array_filter(array_map('trim', explode(',', PanelContent::text($content, 'leaveOut')))));
        if ($leaveOut === []) {
            return $fields;
        }
        $keys = array_map(static fn(PanelField $field): string => $field->key, $fields);
        foreach ($leaveOut as $key) {
            if (!in_array($key, $keys, true)) {
                $this->problems->add(sprintf(
                    '%s: "%s" is set to be left out, but isn\'t one of its questions; there was nothing to leave out.',
                    $sectionName,
                    $key
                ));
                // Marked once every section is read (build()), so a real question
                // of a later section keeps its own label.
                $this->staleLeaveOuts[$key] = true;
            }
        }
        $kept = [];
        foreach ($fields as $field) {
            if (in_array($field->key, $leaveOut, true)) {
                $this->leftOut[$field->key] = true;
            } else {
                $kept[] = $field;
            }
        }
        return $kept;
    }

    /**
     * Returns the section's [field, value] condition if it is sound, else null
     * (with a problem recorded when a condition was set but cannot be used).
     *
     * A condition that cannot be used is dropped rather than kept: a section
     * waiting on a field that never takes the value would never show.
     *
     * @param Block                     $block
     * @param string                    $sectionName
     * @param array<string, PanelField> $earlier Fields in earlier sections, by key
     * @param array<string, true>       $allKeys Every key read on the form
     * @return array{0: string, 1: string}|null
     */
    private function checkedCondition(Block $block, string $sectionName, array $earlier, array $allKeys): ?array
    {
        [$fieldKey, $value, $isOldStyle] = self::conditionOf($block);

        if ($fieldKey === '' && $value === '') {
            return null;
        }

        $problem = null;
        $controller = $earlier[$fieldKey] ?? null;

        if ($fieldKey === '' || $value === '') {
            $problem = 'its "show when" needs both a field and a value';
        } elseif ($controller === null && isset($allKeys[$fieldKey])) {
            $problem = sprintf('it can only depend on a field in an earlier section, and "%s" is not', $fieldKey);
        } elseif ($controller === null && isset($this->leftOut[$fieldKey])) {
            $problem = sprintf('its "show when" question "%s" is left out on this form', $fieldKey);
        } elseif ($controller === null) {
            $problem = sprintf('its "show when" field "%s" is not on this form', $fieldKey);
        } elseif (!$controller->canControlConditions()) {
            $problem = sprintf('its "show when" field "%s" must be a radio or dropdown question', $fieldKey);
        } elseif (!in_array($value, $controller->options, true)) {
            $problem = sprintf('"%s" is not one of the options of "%s"', $value, $fieldKey);
        }

        if ($problem !== null) {
            $this->problems->add(sprintf('%s: %s, so it is always shown.', $sectionName, $problem));
            return null;
        }

        if ($isOldStyle && $controller !== null) {
            // Still applied, but the select can't show it, and the old fields are
            // no longer in the blueprint: re-saving the block would drop it.
            $this->problems->add(sprintf(
                '%s: its "show when" was set the old way, so "Only show this section when…" shows '
                . '"Always show". Pick it again there ("%s", answer "%s") and save, or it will be lost '
                . 'the next time this section is saved.',
                $sectionName,
                $controller->label !== '' ? $controller->label : $fieldKey,
                $value
            ));
        }

        return [$fieldKey, $value];
    }
}
