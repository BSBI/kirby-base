<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

/**
 * Materialises glossary links into block HTML.
 *
 * For each supplied term, the first available whole-word occurrence (outside
 * tags and existing links) is wrapped in an anchor whose href is the glossary
 * item's /@/page/{id} permalink, so the link survives page moves and is
 * resolved to a URL at render time. That permalink form (the same one Kirby's
 * writer stores) is required: Kirby's Sane\Html sanitiser strips hrefs with a
 * page:// scheme on save. Occurrence detection is delegated to
 * GlossaryMatcherService so preview and apply always agree on what matches.
 */
final readonly class GlossaryLinkApplier
{
    /**
     * Constructor.
     *
     * @param GlossaryMatcherService $matcher The matcher used to locate occurrences
     */
    public function __construct(
        private GlossaryMatcherService $matcher = new GlossaryMatcherService(),
    ) {
    }

    /**
     * Wrap the first occurrence of each term in a glossary permalink anchor.
     *
     * @param string $html The block HTML
     * @param array<string, string> $termUuids Map of term => item page UUID (bare or page:// form)
     * @return string The HTML with links applied
     */
    public function applyLinks(string $html, array $termUuids): string
    {
        if ($termUuids === [] || $html === '') {
            return $html;
        }

        $matches = $this->matcher->findMatches(array_keys($termUuids), $html);

        // apply from the last match backwards so earlier offsets stay valid
        usort($matches, static fn ($a, $b): int => $b->getOffset() <=> $a->getOffset());

        foreach ($matches as $match) {
            $uuid = $termUuids[$match->getTerm()] ?? '';
            if ($uuid === '') {
                continue;
            }

            // /@/page/ permalink form: page:// hrefs are stripped by Kirby's
            // Sane\Html sanitiser when the content is next saved in the panel
            $href = '/@/page/' . (str_starts_with($uuid, 'page://') ? substr($uuid, 7) : $uuid);
            // matchedText is a verbatim substring of the source HTML's text
            // segments (never contains markup); the href is escaped so nothing
            // can break out of the attribute
            // data-glossary identifies glossary links in stored content (the
            // panel writer parses them as the glossaryLink mark and styles
            // them); Sane\Html allows data-* attributes so it survives saves
            $link = '<a href="' . htmlspecialchars($href, ENT_QUOTES) . '" data-glossary="true">'
                . htmlspecialchars($match->getMatchedText(), ENT_NOQUOTES) . '</a>';
            $html = substr_replace($html, $link, $match->getOffset(), strlen($match->getMatchedText()));
        }

        return $html;
    }
}
