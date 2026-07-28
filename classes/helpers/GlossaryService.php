<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

use BSBI\WebBase\models\GlossaryItem;
use BSBI\WebBase\models\GlossaryList;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Cms\Site;
use Kirby\Toolkit\Str;
use Throwable;

/**
 * Request-scoped entry point for glossary link enrichment.
 *
 * Resolves the glossary page from the `glossaryLocation` site field (a pages
 * field, so editors control where the glossary lives), falling back to the
 * `glossary.page` config option (a page path, resolved by targeted
 * path-walking — never a full-index scan). Builds the GlossaryList once per
 * request and enriches block HTML via GlossaryLinkEnricher. When neither
 * source is set the feature is dormant and enrichment is a pass-through.
 */
final class GlossaryService
{
    /** @var GlossaryService|null The shared per-request instance */
    private static ?GlossaryService $instance = null;

    /** @var App|null The App the shared instance was built for */
    private static ?App $instanceApp = null;

    /** @var GlossaryList|null The glossary items, built once per request */
    private ?GlossaryList $glossary = null;

    /** @var Page|null The resolved glossary page */
    private ?Page $glossaryPage = null;

    /** @var bool Whether glossary page resolution has already run */
    private bool $glossaryPageResolved = false;

    /**
     * Constructor.
     *
     * @param App $kirby The Kirby application instance
     * @param Site $site The site instance
     * @param GlossaryLinkEnricher $enricher The link enricher
     */
    public function __construct(
        private readonly App $kirby,
        private readonly Site $site,
        private readonly GlossaryLinkEnricher $enricher = new GlossaryLinkEnricher(),
    ) {
    }

    /**
     * Get a shared per-request instance, so callers without access to
     * dependency injection (e.g. snippets rendered many times on one page)
     * reuse a single glossary build.
     *
     * @param App $kirby The Kirby application instance
     * @param Site $site The site instance
     * @return GlossaryService
     */
    public static function instance(App $kirby, Site $site): GlossaryService
    {
        if (self::$instance === null || self::$instanceApp !== $kirby) {
            self::$instance = new GlossaryService($kirby, $site);
            self::$instanceApp = $kirby;
        }
        return self::$instance;
    }

    /**
     * Whether the glossary feature is enabled (a glossary page is resolvable
     * from the `glossaryLocation` site field or the `glossary.page` option).
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->getGlossaryPage() !== null;
    }

    /**
     * Resolve the glossary page: the `glossaryLocation` site field wins
     * (editor-controlled, stored as a page UUID so it survives page moves),
     * with the `glossary.page` config path as fallback. Resolved once per
     * request.
     *
     * @return Page|null The glossary page, or null when not configured
     */
    public function getGlossaryPage(): ?Page
    {
        if ($this->glossaryPageResolved) {
            return $this->glossaryPage;
        }

        $this->glossaryPageResolved = true;

        try {
            $fieldReader = new KirbyFieldReader($this->kirby, $this->site);
            return $this->glossaryPage = $fieldReader->getSiteFieldAsPage('glossaryLocation');
        } catch (KirbyRetrievalException) {
            // field not set — fall through to the config option
        } catch (Throwable $e) {
            KirbyBaseHelper::writeToLogFile(
                'glossary-errors',
                'Glossary location site field could not be resolved: ' . $e->getMessage()
            );
        }

        $path = $this->getGlossaryPagePath();

        if ($path !== '') {
            $configPage = $this->site->find($path);
            if ($configPage instanceof Page) {
                return $this->glossaryPage = $configPage;
            }
        }

        return null;
    }

    /**
     * Get the glossary items, building the list on first use.
     *
     * @return GlossaryList
     */
    public function getGlossary(): GlossaryList
    {
        return $this->glossary ??= $this->buildGlossary();
    }

    /**
     * Enrich glossary links in block HTML with definition title attributes.
     * A pass-through when the feature is disabled or the HTML has no links.
     *
     * @param string $html The block HTML
     * @return string The enriched HTML
     */
    public function enrichHtml(string $html): string
    {
        if ($html === '' || stripos($html, '<a') === false || !$this->isEnabled()) {
            return $html;
        }

        try {
            return $this->enricher->enrich($html, $this->getGlossary());
        } catch (Throwable $e) {
            KirbyBaseHelper::writeToLogFile('glossary-errors', 'Glossary enrichment failed: ' . $e->getMessage());
            return $html;
        }
    }

    /**
     * Get the glossary items in the shape needed by the writer toolbar
     * "insert glossary link" picker: one entry per term with the item page
     * UUID as the value and a shortened plain-text definition for context.
     * Items without a UUID are skipped (they cannot be linked reliably).
     *
     * @return array<int, array{title: string, uuid: string, definition: string}>
     */
    public function getItemsForPicker(): array
    {
        $items = [];

        foreach ($this->getGlossary()->getListItems() as $item) {
            if (!$item->hasUuid()) {
                continue;
            }

            $items[] = [
                'title' => $item->getTitle(),
                'uuid' => $item->getUuid(),
                'definition' => Str::short($item->getDefinition(), 80),
            ];
        }

        return $items;
    }

    /**
     * Get the configured glossary page path.
     *
     * @return string The path, or an empty string when not configured
     */
    private function getGlossaryPagePath(): string
    {
        $path = $this->kirby->option('glossary.page', '');
        return is_string($path) ? $path : '';
    }

    /**
     * Build the glossary list from the listed children of the configured
     * glossary page. Returns an empty list when the page cannot be found.
     *
     * @return GlossaryList
     */
    private function buildGlossary(): GlossaryList
    {
        $glossaryPage = $this->getGlossaryPage();

        if ($glossaryPage === null) {
            return new GlossaryList();
        }

        return $this->buildGlossaryFromPage($glossaryPage);
    }

    /**
     * Build a glossary list from the listed children of a glossary page.
     * Public so listing pages can build their glossary directly from the page
     * being rendered, independently of the `glossary.page` option.
     *
     * @param Page $glossaryPage The glossary listing page
     * @return GlossaryList
     */
    public function buildGlossaryFromPage(Page $glossaryPage): GlossaryList
    {
        $glossary = new GlossaryList();

        try {
            // a plain reader (no glossary service) so building the glossary
            // can never recurse into enrichment
            $fieldReader = new KirbyFieldReader($this->kirby, $this->site);

            foreach ($glossaryPage->children()->listed() as $itemPage) {
                $definitionHtml = $this->getDefinitionHtml($fieldReader, $itemPage);
                $item = new GlossaryItem($itemPage->title()->toString(), $itemPage->url());
                $item->setSlug($itemPage->slug())
                    ->setDefinition($this->toPlainText($definitionHtml))
                    ->setDefinitionHtml($definitionHtml)
                    ->setExtendedContentHtml($this->getExtendedContentHtml($fieldReader, $itemPage))
                    ->setUuid($itemPage->uuid()->toString())
                    ->setPanelUrl($itemPage->panel()->url());
                $glossary->addListItem($item);
            }
        } catch (Throwable $e) {
            KirbyBaseHelper::writeToLogFile('glossary-errors', 'Glossary build failed: ' . $e->getMessage());
        }

        return $glossary;
    }

    /**
     * Get an item's optional extended content blocks rendered as HTML.
     *
     * @param KirbyFieldReader $fieldReader The field reader
     * @param Page $itemPage The glossary item page
     * @return string The extended content HTML, or an empty string when the field is empty
     */
    private function getExtendedContentHtml(KirbyFieldReader $fieldReader, Page $itemPage): string
    {
        try {
            return $fieldReader->getPageFieldAsBlocksHtml($itemPage, 'extendedContent');
        } catch (KirbyRetrievalException) {
            return '';
        }
    }

    /**
     * Get an item's definition as HTML with permalinks resolved, so links to
     * other glossary terms work when the definition is displayed.
     *
     * @param KirbyFieldReader $fieldReader The field reader
     * @param Page $itemPage The glossary item page
     * @return string The definition HTML, or an empty string when the field is empty
     */
    private function getDefinitionHtml(KirbyFieldReader $fieldReader, Page $itemPage): string
    {
        try {
            $definitionField = $fieldReader->getPageField($itemPage, 'definition');
            /** @noinspection PhpUndefinedMethodInspection */
            return $definitionField->permalinksToUrls()->toString();
        } catch (KirbyRetrievalException) {
            return '';
        }
    }

    /**
     * Reduce definition HTML to plain text for use in a title attribute:
     * links and markup stripped, entities decoded.
     *
     * @param string $html The definition HTML
     * @return string The plain-text definition
     */
    private function toPlainText(string $html): string
    {
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5));
    }
}
