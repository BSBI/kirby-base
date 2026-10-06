<?php

declare(strict_types=1);

namespace BSBI\WebBase\forms;

use BSBI\WebBase\forms\panel\PanelContent;
use BSBI\WebBase\helpers\ContentIndexRegistry;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use Throwable;

/**
 * The choices offered to editors building forms in the panel, and how an
 * editor-typed form type is stored.
 *
 * Form types group submissions in the site Forms tab and its exports, and
 * editors deliberately collate different forms under one type. The types are
 * managed in one place, the Form library's Form types list, and a form picks
 * one (or a type already on older submissions), so near-duplicates cannot
 * creep in. "Report as" columns likewise
 * offer the export columns already chosen elsewhere, so editors reuse a name
 * rather than inventing a near-duplicate.
 */
final class FormBuilderOptions
{
    /**
     * Returns a form type in the stored lower_snake form: lower case, each run
     * of other characters replaced by one underscore, no leading or trailing
     * underscores. Returns an empty string if nothing usable is left.
     *
     * @param string $formType As typed or stored by the editor
     */
    public static function normaliseFormType(string $formType): string
    {
        $snake = (string) preg_replace('/[^a-z0-9]+/', '_', strtolower($formType));
        return trim($snake, '_');
    }

    /**
     * Returns the form type choices, stored value => label: each name in the
     * library's Form types list (stored normalised, labelled as written), then
     * any type found only on submissions, offered exactly as stored so a form
     * can be collated with an older one. Sorted by label, case-insensitively.
     *
     * @param string[] $libraryNames    Names from the library's Form types list
     * @param string[] $submissionTypes Form types already in use (submissions, forms)
     * @return array<string, string>
     */
    public static function formTypeOptions(array $libraryNames, array $submissionTypes): array
    {
        $options = [];
        foreach ($libraryNames as $name) {
            $value = self::normaliseFormType($name);
            if ($value !== '' && !isset($options[$value])) {
                $options[$value] = trim($name);
            }
        }
        foreach ($submissionTypes as $type) {
            if ($type !== '' && !isset($options[$type])) {
                $options[$type] = $type;
            }
        }
        uasort($options, 'strcasecmp');
        return $options;
    }

    /**
     * Returns choices for picking an existing panel-built form, page UUID =>
     * "Title (Parent title)", sorted by that label case-insensitively. The
     * parent tells apart forms with the same title.
     *
     * @param array<array{uuid: string, title: string, parent: string}> $forms
     * @return array<string, string>
     */
    public static function formChoices(array $forms): array
    {
        $choices = [];
        foreach ($forms as $form) {
            $choices[$form['uuid']] = $form['parent'] !== ''
                ? $form['title'] . ' (' . $form['parent'] . ')'
                : $form['title'];
        }
        uasort($choices, 'strcasecmp');
        return $choices;
    }

    /**
     * Returns choices for every panel-built form (see formChoices()), read
     * from the form_builders content index rather than the page tree.
     *
     * @param App $kirby
     * @return array<string, string>
     */
    public static function formBuilderChoices(App $kirby): array
    {
        $forms = [];
        try {
            $manager = ContentIndexRegistry::get('form_builders');
            $pageIds = $manager !== null ? $manager->query()->getPageIds() : [];
        } catch (Throwable) {
            $pageIds = [];
        }
        foreach ($pageIds as $pageId) {
            $page = $kirby->site()->findPageOrDraft($pageId);
            if (!$page instanceof Page) {
                continue;
            }
            $parent = $page->parent();
            $forms[] = [
                'uuid'   => $page->uuid()->toString(),
                'title'  => $page->title()->toString(),
                'parent' => $parent instanceof Page ? $parent->title()->toString() : '',
            ];
        }
        return self::formChoices($forms);
    }

    /**
     * Returns the distinct "Report as" columns in the given lists, each entry
     * of which may be a single column or several, comma-separated (as a tags
     * field or the form builder index stores them), sorted case-insensitively.
     *
     * @param string[] ...$sources Lists of columns
     * @return list<string>
     */
    public static function reportColumns(array ...$sources): array
    {
        $columns = [];
        foreach (array_merge(...$sources) as $entry) {
            foreach (explode(',', $entry) as $column) {
                $columns[] = trim($column);
            }
        }
        return self::distinctSorted($columns);
    }

    /**
     * Returns the form type choices for the form type field: the library's
     * Form types list, plus types already in use (on submissions, and on
     * panel-built forms) so a form whose type was later renamed or removed
     * in the library still shows it. In-use types come from the content
     * indexes, not the page tree.
     *
     * @param App $kirby
     * @return array<string, string> Stored value => label
     */
    public static function formTypeChoices(App $kirby): array
    {
        $names = [];
        $library = $kirby->site()->findPageOrDraft(FormLibraryPanel::SLUG);
        if ($library instanceof Page) {
            foreach (PanelContent::field($library->content(), 'formTypes')->toStructure() as $row) {
                $names[] = PanelContent::text($row->content(), 'name');
            }
        }

        return self::formTypeOptions($names, array_merge(
            self::indexColumn('form_submissions', 'form_type'),
            self::indexColumn('form_builders', 'form_type')
        ));
    }

    /**
     * Returns the "Report as" columns in use on panel-built forms and on the
     * sections in the form library.
     *
     * @param App $kirby
     * @return list<string>
     */
    public static function reportColumnsInUse(App $kirby): array
    {
        $librarySections = [];
        $library = $kirby->site()->findPageOrDraft(FormLibraryPanel::SLUG);
        if ($library instanceof Page) {
            foreach ($library->index(true)->filterBy('intendedTemplate', 'form_section') as $section) {
                foreach (PanelContent::field($section->content(), 'formFields')->toBlocks() as $block) {
                    $librarySections[] = PanelContent::text($block->content(), 'reportAs');
                }
            }
        }

        return self::reportColumns(self::indexColumn('form_builders', 'report_columns'), $librarySections);
    }

    /**
     * Returns one column's values from every row of a content index, or an
     * empty list if the index is not available.
     *
     * @param string $index  Content index name
     * @param string $column Column name
     * @return string[]
     */
    private static function indexColumn(string $index, string $column): array
    {
        try {
            $manager = ContentIndexRegistry::get($index);
            if ($manager === null) {
                return [];
            }
            $values = [];
            foreach ($manager->query()->get() as $row) {
                $value = $row[$column] ?? '';
                $values[] = is_scalar($value) ? (string) $value : '';
            }
            return $values;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Returns the distinct non-empty values, sorted case-insensitively.
     *
     * @param string[] $values
     * @return list<string>
     */
    private static function distinctSorted(array $values): array
    {
        $distinct = array_values(array_unique(array_filter($values, static fn(string $v): bool => $v !== '')));
        usort($distinct, 'strcasecmp');
        return $distinct;
    }
}
