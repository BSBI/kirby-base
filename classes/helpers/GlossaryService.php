<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

use BSBI\WebBase\models\GlossaryItem;
use BSBI\WebBase\models\GlossaryList;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Cms\Site;
use Throwable;

/**
 * Request-scoped entry point for glossary link enrichment.
 *
 * Resolves the glossary page from the `glossary.page` config option (a page
 * path, resolved by targeted path-walking — never a full-index scan), builds
 * the GlossaryList once per request, and enriches block HTML via
 * GlossaryLinkEnricher. When the option is not set the feature is dormant and
 * enrichment is a pass-through.
 */
final class GlossaryService
{
    /** @var GlossaryList|null The glossary items, built once per request */
    private ?GlossaryList $glossary = null;

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
     * Whether the glossary feature is enabled (the `glossary.page` config
     * option is set).
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->getGlossaryPagePath() !== '';
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
        $glossary = new GlossaryList();
        $path = $this->getGlossaryPagePath();

        if ($path === '') {
            return $glossary;
        }

        try {
            $glossaryPage = $this->site->find($path);

            if (!$glossaryPage instanceof Page) {
                return $glossary;
            }

            // a plain reader (no glossary service) so building the glossary
            // can never recurse into enrichment
            $fieldReader = new KirbyFieldReader($this->kirby, $this->site);

            foreach ($glossaryPage->children()->listed() as $itemPage) {
                $definitionHtml = $this->getDefinitionHtml($fieldReader, $itemPage);
                $item = new GlossaryItem($itemPage->title()->toString(), $itemPage->url());
                $item->setSlug($itemPage->slug())
                    ->setDefinition($this->toPlainText($definitionHtml))
                    ->setDefinitionHtml($definitionHtml)
                    ->setType($fieldReader->getPageFieldAsString($itemPage, 'glossaryType'));
                $glossary->addListItem($item);
            }
        } catch (Throwable $e) {
            KirbyBaseHelper::writeToLogFile('glossary-errors', 'Glossary build failed: ' . $e->getMessage());
        }

        return $glossary;
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
