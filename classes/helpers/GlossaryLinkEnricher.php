<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

use BSBI\WebBase\models\GlossaryItem;
use BSBI\WebBase\models\GlossaryList;

/**
 * Adds glossary definitions as title attributes to glossary links in HTML.
 *
 * A pure, stateless service that runs after permalinks have been resolved to
 * URLs: any anchor whose href matches a glossary item's URL has its title
 * attribute set to the item's definition, so the definition appears on hover
 * while remaining single-sourced in the glossary. Only the anchor's opening
 * tag is rewritten; all other HTML is left byte-for-byte untouched.
 */
final readonly class GlossaryLinkEnricher
{
    /**
     * Enrich glossary links in the given HTML with definition title attributes.
     *
     * @param string $html The block HTML to enrich
     * @param GlossaryList $glossary The glossary items to match link URLs against
     * @return string The HTML with glossary link titles injected
     */
    public function enrich(string $html, GlossaryList $glossary): string
    {
        if ($html === '' || !$glossary->hasListItems() || stripos($html, '<a') === false) {
            return $html;
        }

        $result = preg_replace_callback(
            '/<a\b[^>]*>/i',
            fn (array $tagMatch): string => $this->enrichAnchorTag($tagMatch[0], $glossary),
            $html
        );

        return $result ?? $html;
    }

    /**
     * Rewrite a single anchor opening tag, injecting the glossary definition
     * as its title attribute when the href matches a glossary item.
     *
     * @param string $tag The anchor opening tag (e.g. <a href="...">)
     * @param GlossaryList $glossary The glossary items to match against
     * @return string The rewritten tag, or the original when there is no match
     */
    private function enrichAnchorTag(string $tag, GlossaryList $glossary): string
    {
        if (preg_match('/href="([^"]*)"/i', $tag, $hrefMatch) !== 1) {
            return $tag;
        }

        $item = $this->findItemForHref($hrefMatch[1], $glossary);

        if ($item === null || !$item->hasDefinition()) {
            return $tag;
        }

        $title = htmlspecialchars($item->getDefinition(), ENT_QUOTES);

        if (preg_match('/title="[^"]*"/i', $tag) === 1) {
            $replaced = preg_replace('/title="[^"]*"/i', 'title="' . $title . '"', $tag, 1);
            return $replaced ?? $tag;
        }

        return substr($tag, 0, -1) . ' title="' . $title . '">';
    }

    /**
     * Find the glossary item a link href points at, ignoring any fragment.
     *
     * @param string $href The href attribute value
     * @param GlossaryList $glossary The glossary items to match against
     * @return GlossaryItem|null
     */
    private function findItemForHref(string $href, GlossaryList $glossary): ?GlossaryItem
    {
        $item = $glossary->findByUrl($href);

        if ($item !== null) {
            return $item;
        }

        $fragmentPosition = strpos($href, '#');
        if ($fragmentPosition !== false) {
            return $glossary->findByUrl(substr($href, 0, $fragmentPosition));
        }

        return null;
    }
}
