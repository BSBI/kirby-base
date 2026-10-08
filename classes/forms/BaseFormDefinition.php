<?php

declare(strict_types=1);

namespace BSBI\WebBase\forms;

use BSBI\WebBase\forms\panel\PanelFormDefinition;
use Kirby\Cms\Page;

/**
 * Abstract base class for form definitions.
 *
 * Subclasses declare the fixed form fields (and optional sections) and provide
 * a form-type identifier used when storing submissions.
 *
 * Override defineForm() and return any mix of FormFieldSpec and FormSection:
 *
 *   protected function defineForm(): array
 *   {
 *       return [
 *           FormFieldSpec::textbox('location', 'Workshop name/location')->required(),
 *           FormFieldSpec::radioGroup('contact_pref', 'Preferred contact', ['Email', 'Phone']),
 *           FormSection::make('email_section', 'Email details')
 *               ->fields(FormFieldSpec::textbox('email', 'Email address'))
 *               ->showWhen('contact_pref', 'Email'),
 *       ];
 *   }
 *
 * For backward compatibility, overriding defineFields() (which returns only
 * FormFieldSpec objects) is still supported; defineForm() delegates to it by
 * default.  New code should override defineForm() directly.
 *
 * Editors can add questions to a hand-written form through panel sections
 * (an `extraSections` blocks field read by a PanelFormDefinition): see
 * withExtraSections(). They go where extraSectionsAt() says, and every
 * resolving method (getFields(), getFieldGroups(), getFieldNames()) includes
 * them. getSubmissionColumns() stays the hand-written form's own; handlers
 * store the extras using extraSubmissionColumns().
 */
abstract class BaseFormDefinition
{
    /** Panel sections added to this form, if any. */
    private ?PanelFormDefinition $extraSections = null;

    /**
     * Returns the ordered list of FormFieldSpec and/or FormSection objects
     * that make up this form.
     *
     * Override this method in your form definition subclass.  You may return
     * a flat list of FormFieldSpec objects, a mix with FormSection groups, or
     * any combination.
     *
     * Default implementation calls defineFields() for backward compatibility
     * with subclasses that were written before sections were introduced.
     *
     * @return array<FormFieldSpec|FormSection>
     */
    protected function defineForm(): array
    {
        return $this->defineFields();
    }

    /**
     * Backward-compatible hook for flat (no sections) form definitions.
     * Override defineForm() instead for new form definitions.
     *
     * @return FormFieldSpec[]
     */
    protected function defineFields(): array
    {
        return [];
    }

    /**
     * Returns a short identifier for this form type (e.g. 'training_feedback').
     * Stored on every form_submission page for filtering and export.
     */
    abstract public function getFormType(): string;

    /**
     * Returns a custom handler to invoke when the form is submitted, or null
     * to use the default behaviour (save a form_submission Kirby child page and
     * send a notification email).
     *
     * Override this in your definition subclass to replace the default action:
     *
     *   public function getSubmissionHandler(): ?FormSubmissionHandler
     *   {
     *       return new StripeCheckoutHandler();
     *   }
     */
    public function getSubmissionHandler(): ?FormSubmissionHandler
    {
        return null;
    }

    /**
     * Returns what a submission of this form stores for each question, keyed by
     * POST key: the label the respondent saw and the CSV export column.
     *
     * Empty (the default) keeps the original behaviour: every POST key is stored
     * under a title-cased version of the key. A non-empty map stores only these
     * keys (see FormSubmissionBuilder).
     *
     * @return array<string, array{label: string, column: string}>
     */
    public function getSubmissionColumns(): array
    {
        return [];
    }

    /**
     * Returns the separate responses a submission also makes: form type =>
     * the keys whose answers it holds. None for a hand-written form; a panel
     * section can ask for one (PanelFormDefinition).
     *
     * @param array<mixed> $postData The submitted data
     * @return array<string, list<string>>
     */
    public function separateCopies(array $postData): array
    {
        return [];
    }

    /**
     * Adds editor-defined panel sections to this form, at extraSectionsAt().
     *
     * Build the panel definition with this form's getFieldNames() as its
     * reserved keys, so an extra question can't take a fixed question's key.
     *
     * @param PanelFormDefinition $extraSections The panel sections to add
     * @return static
     */
    public function withExtraSections(PanelFormDefinition $extraSections): static
    {
        $this->extraSections = $extraSections;
        return $this;
    }

    /**
     * Returns each extra (panel) question's label and export column, keyed by
     * POST key, or an empty array when the form has no extra sections. A
     * submission handler stores these keys as a panel-built form does.
     *
     * @return array<string, array{label: string, column: string}>
     */
    public function extraSubmissionColumns(): array
    {
        return $this->extraSections?->getSubmissionColumns() ?? [];
    }

    /**
     * Returns where extra sections go: the number of defineForm() items that
     * come before them. Null (the default) puts them at the end.
     *
     * @return int|null
     */
    protected function extraSectionsAt(): ?int
    {
        return null;
    }

    /**
     * Resolves all fixed fields against panel-supplied overrides from the given
     * Kirby page and returns an array of ready-to-render ResolvedFormField objects.
     *
     * Sections are flattened: fields inside sections are included in order.
     * For section-aware rendering use getFieldGroups() instead.
     *
     * @param Page $page The Kirby page holding the panel override field values
     * @return ResolvedFormField[]
     */
    public function getFields(Page $page): array
    {
        $resolved = [];

        foreach ($this->getAllSpecs() as $spec) {
            $resolved[] = $this->resolveSpec($spec, $page);
        }

        return $resolved;
    }

    /**
     * Resolves defineForm() against panel-supplied overrides from the given
     * Kirby page and returns an ordered mixed array of ResolvedFormField and
     * ResolvedFormSection objects suitable for section-aware rendering.
     *
     * @param Page $page The Kirby page holding the panel override field values
     * @return array<ResolvedFormField|ResolvedFormSection>
     */
    public function getFieldGroups(Page $page): array
    {
        $groups = [];

        foreach ($this->formItems() as $item) {
            if ($item instanceof FormSection) {
                $groups[] = $item->resolve($page, fn(FormFieldSpec $s, Page $p) => $this->resolveSpec($s, $p));
            } else {
                $groups[] = $this->resolveSpec($item, $page);
            }
        }

        return $groups;
    }

    /**
     * Returns the field names of all submittable fixed fields (including those
     * inside sections), in definition order.  Display-only types (info,
     * site-blocks) are excluded because they produce no POST key.
     *
     * @return string[]
     */
    public function getFieldNames(): array
    {
        $displayOnly = [FormFieldSpec::TYPE_INFO, FormFieldSpec::TYPE_SITE_BLOCKS];

        return array_values(array_map(
            static fn(FormFieldSpec $spec): string => $spec->getName(),
            array_filter(
                $this->getAllSpecs(),
                static fn(FormFieldSpec $spec): bool => !in_array($spec->getType(), $displayOnly, true),
            )
        ));
    }

    /**
     * Returns a merged Kirby-blueprint-compatible field definition array
     * covering all overridable properties across every field in this definition
     * (including fields inside sections).
     *
     * Developers can call this (e.g. via a CLI command or temporary debug route)
     * to generate the YAML to paste into a page blueprint.
     *
     * @return array<string, array<string, mixed>>
     */
    public function toBlueprintFields(): array
    {
        $fields = [];

        foreach ($this->getAllSpecs(false) as $spec) {
            $fields = array_merge($fields, $spec->toBlueprintFields());
        }

        return $fields;
    }

    // ── Private helpers ─────────────────────────────────────────────────────

    /**
     * Returns defineForm() with any extra sections inserted at extraSectionsAt().
     *
     * @param bool $withExtras False for the hand-written items only
     * @return array<FormFieldSpec|FormSection>
     */
    private function formItems(bool $withExtras = true): array
    {
        $items = $this->defineForm();
        if (!$withExtras || $this->extraSections === null) {
            return $items;
        }

        $extras = $this->extraSections->defineForm();
        $at = $this->extraSectionsAt() ?? count($items);
        array_splice($items, max(0, min($at, count($items))), 0, $extras);
        return $items;
    }

    /**
     * Returns a flat list of all FormFieldSpec objects from formItems(),
     * extracting specs from inside FormSection objects.
     *
     * @param bool $withExtras False for the hand-written fields only
     * @return FormFieldSpec[]
     */
    private function getAllSpecs(bool $withExtras = true): array
    {
        $specs = [];

        foreach ($this->formItems($withExtras) as $item) {
            if ($item instanceof FormSection) {
                foreach ($item->getFields() as $spec) {
                    $specs[] = $spec;
                }
            } else {
                $specs[] = $item;
            }
        }

        return $specs;
    }

    /**
     * Resolves a single FormFieldSpec against panel override values from the
     * given page and returns a ResolvedFormField.
     *
     * @param FormFieldSpec $spec
     * @param Page          $page
     * @return ResolvedFormField
     */
    private function resolveSpec(FormFieldSpec $spec, Page $page): ResolvedFormField
    {
        $panelValues = [];

        foreach (array_keys($spec->getOverridableProperties()) as $property) {
            $blueprintFieldName = $spec->getBlueprintFieldName($property);
            $panelValue = (string) $page->content()->get($blueprintFieldName)->value();

            if ($panelValue !== '') {
                $panelValues[$property] = $panelValue;
            }
        }

        return $spec->resolve($panelValues);
    }
}
