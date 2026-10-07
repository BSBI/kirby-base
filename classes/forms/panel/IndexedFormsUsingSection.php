<?php

declare(strict_types=1);

namespace BSBI\WebBase\forms\panel;

use BSBI\WebBase\helpers\ContentIndexRegistry;
use BSBI\WebBase\helpers\KirbyBaseHelper;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use Throwable;

/**
 * Finds the forms that use a library section from the `form_builders`
 * content index, whose `section_ids` column lists every library section a
 * form uses, including the bases its variations extend. No page-tree walk.
 */
final readonly class IndexedFormsUsingSection implements FormsUsingSection
{
    /**
     * @param App $kirby The Kirby app to look the forms up in
     */
    public function __construct(private App $kirby)
    {
    }

    /**
     * Returns the form pages (drafts included) that use the section. An
     * unavailable index is logged and gives none.
     *
     * @param Page $section A library section page
     * @return list<Page>
     */
    public function forms(Page $section): array
    {
        try {
            $manager = ContentIndexRegistry::get('form_builders');
            $pageIds = $manager !== null
                ? $manager->query()->whereContains('section_ids', $section->id())->getPageIds()
                : [];
        } catch (Throwable $e) {
            KirbyBaseHelper::writeToLogFile('search-index', 'Forms using section: index unavailable: ' . $e->getMessage());
            return [];
        }

        $forms = [];
        foreach ($pageIds as $pageId) {
            $form = $this->kirby->site()->findPageOrDraft($pageId);
            if ($form instanceof Page) {
                $forms[] = $form;
            }
        }
        return $forms;
    }
}
