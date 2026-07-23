<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

use BSBI\WebBase\models\GlossaryMatch;

/**
 * Finds occurrences of glossary terms within block HTML.
 *
 * A pure, stateless service: glossary terms and block HTML in, GlossaryMatch
 * objects out. Matching is case-insensitive and whole-word, skips text that is
 * inside HTML tags or existing links, reports only the first occurrence of
 * each term, and prefers longer terms over shorter terms they contain.
 */
final readonly class GlossaryMatcherService
{
    private const int CONTEXT_LENGTH = 40;

    /**
     * Find glossary term matches within a block's HTML.
     *
     * @param string[] $terms The glossary terms to look for
     * @param string $html The block HTML to search
     * @param string $blockId The UUID of the block being searched
     * @return GlossaryMatch[] The matches, at most one per term
     */
    public function findMatches(array $terms, string $html, string $blockId = ''): array
    {
        $terms = array_values(array_filter(
            array_map('trim', $terms),
            static fn (string $term): bool => $term !== ''
        ));

        if ($terms === [] || trim($html) === '') {
            return [];
        }

        // longest first, so "basal rosette" wins over a contained "rosette"
        usort($terms, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        $segments = $this->getLinkableTextSegments($html);
        $matches = [];
        /** @var array<int, array{int, int}> $claimedRanges */
        $claimedRanges = [];

        foreach ($terms as $term) {
            $match = $this->findFirstMatchForTerm($term, $segments, $claimedRanges, $blockId);
            if ($match !== null) {
                $matches[] = $match;
                $claimedRanges[] = [$match->getOffset(), $match->getOffset() + strlen($match->getMatchedText())];
            }
        }

        return $matches;
    }

    /**
     * Split HTML into the text segments where a glossary link could be added:
     * everything outside HTML tags and outside existing <a> elements.
     *
     * @param string $html The block HTML
     * @return array<int, array{string, int}> Segments as [text, byte offset in original HTML]
     */
    private function getLinkableTextSegments(string $html): array
    {
        $segments = preg_split(
            '/(<a\b.*?<\/a>|<[^>]*>)/is',
            $html,
            -1,
            PREG_SPLIT_OFFSET_CAPTURE | PREG_SPLIT_NO_EMPTY
        );

        if (!is_array($segments)) {
            return [];
        }

        /** @var list<array{string, int<0, max>}> $segments */
        return $segments;
    }

    /**
     * Find the first occurrence of a term across the text segments that does
     * not overlap a range already claimed by another (longer) term.
     *
     * @param string $term The glossary term to look for
     * @param array<int, array{string, int}> $segments Text segments from getLinkableTextSegments
     * @param array<int, array{int, int}> $claimedRanges Byte ranges already matched by other terms
     * @param string $blockId The UUID of the block being searched
     * @return GlossaryMatch|null The first available match, or null when the term is not found
     */
    private function findFirstMatchForTerm(
        string $term,
        array $segments,
        array $claimedRanges,
        string $blockId
    ): ?GlossaryMatch {
        $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($term, '/') . '(?![\p{L}\p{N}])/ui';

        foreach ($segments as [$text, $segmentOffset]) {
            if (preg_match_all($pattern, $text, $found, PREG_OFFSET_CAPTURE) === false) {
                continue;
            }

            foreach ($found[0] as [$matchedText, $textOffset]) {
                $start = $segmentOffset + $textOffset;
                $end = $start + strlen($matchedText);

                if ($this->overlapsClaimedRange($start, $end, $claimedRanges)) {
                    continue;
                }

                return new GlossaryMatch(
                    $term,
                    $matchedText,
                    $blockId,
                    $start,
                    $this->extractContextBefore($text, $textOffset),
                    $this->extractContextAfter($text, $textOffset + strlen($matchedText))
                );
            }
        }

        return null;
    }

    /**
     * Check whether a byte range overlaps any already-claimed range.
     *
     * @param int $start Range start (inclusive)
     * @param int $end Range end (exclusive)
     * @param array<int, array{int, int}> $claimedRanges Already-claimed byte ranges
     * @return bool
     */
    private function overlapsClaimedRange(int $start, int $end, array $claimedRanges): bool
    {
        foreach ($claimedRanges as [$claimedStart, $claimedEnd]) {
            if ($start < $claimedEnd && $end > $claimedStart) {
                return true;
            }
        }
        return false;
    }

    /**
     * Extract the plain text immediately before a match, trimmed to whole words.
     *
     * @param string $text The segment text the match was found in
     * @param int $position Byte position of the match within the segment
     * @return string
     */
    private function extractContextBefore(string $text, int $position): string
    {
        $start = max(0, $position - self::CONTEXT_LENGTH);
        $chunk = substr($text, $start, $position - $start);

        if ($start > 0) {
            $firstSpace = strpos($chunk, ' ');
            $chunk = $firstSpace === false ? '' : substr($chunk, $firstSpace + 1);
        }

        return trim($chunk);
    }

    /**
     * Extract the plain text immediately after a match, trimmed to whole words.
     *
     * @param string $text The segment text the match was found in
     * @param int $position Byte position just after the match within the segment
     * @return string
     */
    private function extractContextAfter(string $text, int $position): string
    {
        $chunk = substr($text, $position, self::CONTEXT_LENGTH);

        if ($position + self::CONTEXT_LENGTH < strlen($text)) {
            $lastSpace = strrpos($chunk, ' ');
            $chunk = $lastSpace === false ? '' : substr($chunk, 0, $lastSpace);
        }

        return trim($chunk);
    }
}
