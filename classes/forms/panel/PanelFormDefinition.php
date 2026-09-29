<?php

declare(strict_types=1);

namespace BSBI\WebBase\forms\panel;

use BSBI\WebBase\forms\BaseFormDefinition;
use BSBI\WebBase\forms\FormSection;
use Kirby\Cms\Block;
use Kirby\Cms\Page;

/**
 * A form definition read from panel content instead of written in PHP.
 *
 * The form page holds a blocks field (default `formSections`) of:
 *  - `form-section-ref`: a library section chosen in the `section` pages field,
 *    with an optional `title` overriding the section's `legend`
 *  - `form-section-inline`: a section defined on the form itself, with a
 *    `title` and its own `formFields` blocks
 * Either may set `showWhenField` (a field key) and `showWhenValue`, to show
 * the section only when an earlier radio or dropdown field has that value.
 *
 * Everything downstream (resolving, rendering, submission handling) is the
 * same as for a hand-written definition. Bad content never stops the form
 * rendering: the offending piece is left out and described by validate().
 */
class PanelFormDefinition extends BaseFormDefinition
{
    /** @var array<FormSection>|null Built on first use */
    private ?array $sections = null;

    private FormProblems $problems;

    /**
     * @param Page                $page          The form page holding the section blocks
     * @param string              $formType      Stored on every submission (see getFormType())
     * @param SectionPageResolver $resolver      Finds referenced library sections
     * @param string              $sectionsField Name of the blocks field holding the sections
     */
    public function __construct(
        private readonly Page $page,
        private readonly string $formType,
        private readonly SectionPageResolver $resolver,
        private readonly string $sectionsField = 'formSections',
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
        $blocks = PanelContent::blocks($this->page->content(), $this->sectionsField);

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
            foreach ($section['fields'] as $field) {
                if (!$this->keyIsUsable($field, $section['name'], $seen)) {
                    continue;
                }
                $seen[$field->key] = $field;
                $specs[] = $field->spec;
            }

            $formSection = FormSection::make($section['id'], $section['legend'])->fields(...$specs);
            $condition = $this->checkedCondition($section['block'], $section['name'], $earlier, $allKeys);
            if ($condition !== null) {
                $formSection->showWhen($condition[0], $condition[1]);
            }
            $this->sections[] = $formSection;
        }

        return $this->sections;
    }

    /**
     * Reads one section block, or returns null (with a problem) if it cannot be.
     *
     * @param Block              $block
     * @param int                $position      1-based position on the form
     * @param PanelSectionReader $sectionReader
     * @return array{id: string, legend: string, name: string, block: Block, fields: PanelField[]}|null
     */
    private function readSection(Block $block, int $position, PanelSectionReader $sectionReader): ?array
    {
        $content = $block->content();
        $title   = PanelContent::text($content, 'title');

        if ($block->type() === 'form-section-inline') {
            $name = $title !== '' ? sprintf('Section "%s"', $title) : sprintf('Section %d', $position);
            return [
                'id'     => $block->id(),
                'legend' => $title,
                'name'   => $name,
                'block'  => $block,
                'fields' => $sectionReader->readBlocks(
                    PanelContent::blocks($content, 'formFields'),
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
            $legend = $title !== '' ? $title : PanelContent::text($page->content(), 'legend');
            $pageTitle = PanelContent::text($page->content(), 'title');
            $name = sprintf('Section "%s"', $pageTitle !== '' ? $pageTitle : $page->slug());
            return [
                'id'     => $block->id(),
                'legend' => $legend,
                'name'   => $name,
                'block'  => $block,
                'fields' => $sectionReader->read($page, $this->problems),
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
        $fieldKey = PanelContent::text($block->content(), 'showWhenField');
        $value    = PanelContent::text($block->content(), 'showWhenValue');

        if ($fieldKey === '' && $value === '') {
            return null;
        }

        $problem = null;
        $controller = $earlier[$fieldKey] ?? null;

        if ($fieldKey === '' || $value === '') {
            $problem = 'its "show when" needs both a field and a value';
        } elseif ($controller === null && isset($allKeys[$fieldKey])) {
            $problem = sprintf('it can only depend on a field in an earlier section, and "%s" is not', $fieldKey);
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

        return [$fieldKey, $value];
    }
}
