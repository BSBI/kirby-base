<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

/**
 * Numbering style for list blocks (bsbi-web#786): letters or roman numerals.
 *
 * The block's "List style" select sets the HTML `type` attribute on the
 * outermost <ol> of the Writer's stored HTML. A bullet list, a nested list
 * and a list with no (or an unknown) style are returned as written, so only
 * the allow-listed values below can ever reach the attribute.
 */
final class ListBlockStyle
{
    /**
     * Stored value => Panel label. '1' (numbers) is the browser default, so it
     * adds no attribute.
     */
    public const array OPTIONS = [
        '1' => '1. 2. 3.',
        'a' => 'a. b. c.',
        'A' => 'A. B. C.',
        'i' => 'i. ii. iii.',
        'I' => 'I. II. III.',
    ];

    /**
     * @param string $html The list block's stored Writer HTML
     * @param string $style The block's listStyle value
     * @return string The HTML, with type="…" on the outermost <ol> when a
     *                lettered or roman style is chosen
     */
    public static function apply(string $html, string $style): string
    {
        if ($style === '1' || !array_key_exists($style, self::OPTIONS)) {
            return $html;
        }
        if (preg_match('/^(\s*)<ol\b([^>]*)>/', $html, $match) !== 1) {
            return $html;
        }
        $attributes = (string) preg_replace('/\s+type\s*=\s*("[^"]*"|\'[^\']*\'|\S+)/i', '', $match[2]);
        $opening = $match[1] . '<ol' . $attributes . ' type="' . $style . '">';
        return $opening . substr($html, strlen($match[0]));
    }
}
