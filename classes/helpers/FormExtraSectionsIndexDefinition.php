<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

use BSBI\WebBase\forms\FormBuilderOptions;
use BSBI\WebBase\forms\panel\KirbySectionPageResolver;
use BSBI\WebBase\forms\panel\PanelFormDefinition;
use Kirby\Cms\Page;

/**
 * Content index of hand-written forms that take extra panel sections
 * (FormBuilderOptions::extraSectionsTemplates()), recording the library
 * sections each one uses.
 *
 * Kept apart from `form_builders` because that index also feeds "Start from
 * an existing form", the form-type choices and the Report-as columns, none
 * of which these forms belong in. Its one use is finding the forms that use
 * a library section, for key locking (IndexedFormsUsingSection).
 */
class FormExtraSectionsIndexDefinition extends ContentIndexDefinition
{
    /**
     * @param list<string> $templates Templates of forms with extra sections
     */
    public function __construct(private readonly array $templates = [])
    {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'form_extra_sections';
    }

    /**
     * @inheritDoc
     */
    public function getCollectionName(): string
    {
        return 'formExtraSections';
    }

    /**
     * @inheritDoc
     */
    public function getTemplates(): array
    {
        return $this->templates;
    }

    /**
     * @inheritDoc
     */
    public function getColumns(): array
    {
        return [
            'section_ids' => 'TEXT NOT NULL DEFAULT ""',
        ];
    }

    /**
     * @inheritDoc
     */
    public function getIndexes(): array
    {
        return [];
    }

    /**
     * Indexes every form, whatever its status: forms are often unlisted or drafts.
     *
     * @param Page $page
     * @return bool
     */
    public function shouldIndex(Page $page): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function getRowData(Page $page, KirbyBaseHelper $helper): array
    {
        return $this->rowFor($page);
    }

    /**
     * Returns the row for a form: its id and the library sections its extra
     * sections use (including the bases of variations), comma-separated.
     *
     * @param Page $page
     * @return array{page_id: string, section_ids: string}
     */
    public function rowFor(Page $page): array
    {
        $definition = new PanelFormDefinition(
            $page,
            '',
            new KirbySectionPageResolver($page->kirby()),
            FormBuilderOptions::sectionsFieldFor($page)
        );

        return [
            'page_id'     => $page->id(),
            'section_ids' => implode(',', $definition->sectionIds()),
        ];
    }
}
