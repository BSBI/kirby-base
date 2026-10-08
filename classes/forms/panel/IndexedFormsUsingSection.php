<?php

declare(strict_types=1);

namespace BSBI\WebBase\forms\panel;

use BSBI\WebBase\helpers\ContentIndexRegistry;
use BSBI\WebBase\helpers\KirbyBaseHelper;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use Throwable;

/**
 * Finds the forms that use a library section from the `form_builders` and
 * `form_extra_sections` content indexes, whose `section_ids` column lists
 * every library section a form uses, including the bases its variations
 * extend. No page-tree walk.
 */
final readonly class IndexedFormsUsingSection implements FormsUsingSection
{
    /** Content indexes recording the library sections forms use. */
    public const INDEXES = ['form_builders', 'form_extra_sections'];

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
            $pageIds = [];
            foreach (self::INDEXES as $index) {
                $manager = ContentIndexRegistry::get($index);
                if ($manager !== null) {
                    $pageIds = array_merge(
                        $pageIds,
                        $manager->query()->whereContains('section_ids', $section->id())->getPageIds()
                    );
                }
            }
            $pageIds = array_values(array_unique($pageIds));
        } catch (Throwable $e) {
            KirbyBaseHelper::writeToLogFile('search-index', 'Forms using section: index unavailable: ' . $e->getMessage());
            return [];
        }

        $forms = [];
        foreach ($pageIds as $pageId) {
            // findPageOrDraft() misses a draft inside a published page; draft() walks both.
            $form = $this->kirby->site()->findPageOrDraft($pageId) ?? $this->kirby->site()->draft($pageId);
            if ($form instanceof Page) {
                $forms[] = $form;
            }
        }
        return $forms;
    }
}
