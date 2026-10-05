<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

use BSBI\WebBase\forms\FormBuilderOptions;
use BSBI\WebBase\forms\panel\KirbySectionPageResolver;
use BSBI\WebBase\forms\panel\PanelContent;
use BSBI\WebBase\forms\panel\PanelFormDefinition;
use Kirby\Cms\Page;

/**
 * Content index definition for forms built in the panel.
 *
 * Records each form's (normalised) form type and the "Report as" columns its
 * questions use, so the panel can offer them as choices without walking the
 * whole site. The templates default to `form_builder`; a site with another
 * name passes its own.
 *
 * @package BSBI\WebBase\helpers
 */
class FormBuilderIndexDefinition extends ContentIndexDefinition
{
    /**
     * @param string[] $templates Templates of panel-built form pages
     */
    public function __construct(private readonly array $templates = ['form_builder'])
    {
    }

    /**
     * {@inheritDoc}
     */
    public function getName(): string
    {
        return 'form_builders';
    }

    /**
     * {@inheritDoc}
     */
    public function getCollectionName(): string
    {
        return 'formBuilders';
    }

    /**
     * {@inheritDoc}
     */
    public function getTemplates(): array
    {
        return $this->templates;
    }

    /**
     * {@inheritDoc}
     */
    public function getColumns(): array
    {
        return [
            'form_type'      => 'TEXT NOT NULL DEFAULT ""',
            'report_columns' => 'TEXT NOT NULL DEFAULT ""',
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function getIndexes(): array
    {
        return [
            'CREATE INDEX IF NOT EXISTS idx_form_builders_form_type ON content_form_builders (form_type)',
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function getRowData(Page $page, KirbyBaseHelper $helper): array
    {
        return $this->rowFor($page);
    }

    /**
     * Returns the index row for a panel-built form page: its normalised form
     * type and its "Report as" columns (questions that set one, including those
     * in library sections it uses), comma-separated.
     *
     * @param Page $page A panel-built form page
     * @return array{page_id: string, form_type: string, report_columns: string}
     */
    public function rowFor(Page $page): array
    {
        $formType = FormBuilderOptions::normaliseFormType(PanelContent::text($page->content(), 'form_type'));
        $definition = new PanelFormDefinition($page, $formType, new KirbySectionPageResolver($page->kirby()));

        $reportColumns = [];
        foreach ($definition->getSubmissionColumns() as $key => $column) {
            if ($column['column'] !== $key) {
                $reportColumns[] = $column['column'];
            }
        }

        return [
            'page_id'        => $page->id(),
            'form_type'      => $formType,
            'report_columns' => implode(', ', array_values(array_unique($reportColumns))),
        ];
    }
}
