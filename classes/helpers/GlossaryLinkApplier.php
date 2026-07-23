<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

/**
 * Materialises glossary links into block HTML.
 *
 * For each supplied term, the first available whole-word occurrence (outside
 * tags and existing links) is wrapped in an anchor whose href is the glossary
 * item's page:// permalink, so the link survives page moves and is resolved
 * to a URL at render time. Occurrence detection is delegated to
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

            $href = str_starts_with($uuid, 'page://') ? $uuid : 'page://' . $uuid;
            // matchedText is a verbatim substring of the source HTML's text
            // segments (never contains markup); the href is escaped so nothing
            // can break out of the attribute
            $link = '<a href="' . htmlspecialchars($href, ENT_QUOTES) . '">'
                . htmlspecialchars($match->getMatchedText(), ENT_NOQUOTES) . '</a>';
            $html = substr_replace($html, $link, $match->getOffset(), strlen($match->getMatchedText()));
        }

        return $html;
    }
}
