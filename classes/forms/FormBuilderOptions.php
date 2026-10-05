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
 * editors deliberately collate different forms under one type, so the form
 * type field offers every type already in use. "Report as" columns likewise
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
     * Returns the distinct, non-empty form types from the given sources,
     * sorted case-insensitively. Values are kept as stored: an older
     * submission's type is offered exactly, so a form can join its group.
     *
     * @param string[] ...$sources Lists of form types
     * @return list<string>
     */
    public static function formTypes(array ...$sources): array
    {
        return self::distinctSorted(array_merge(...$sources));
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
     * Returns the form types in use: those stored on submissions and those set
     * on panel-built forms (read from the content indexes, not the page tree).
     *
     * @return list<string>
     */
    public static function formTypesInUse(): array
    {
        return self::formTypes(
            self::indexColumn('form_submissions', 'form_type'),
            self::indexColumn('form_builders', 'form_type')
        );
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
