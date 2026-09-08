<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\snippets;

use PHPUnit\Framework\TestCase;

/**
 * Guard for the `header` snippet's meta tags.
 *
 * A page description holding a double quote — three person pages had block
 * JSON pasted into the SEO description — closed the `content` attribute and
 * the rest rendered as visible text above the site header. Every value
 * written into a meta attribute must go through esc(…, 'attr'). The full
 * header needs the whole site to render, so this checks the source directly.
 */
final class HeaderSnippetTest extends TestCase
{
    public function testEveryMetaContentAttributeIsEscaped(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/snippets/header.php');

        preg_match_all('/content="<\?=\s*(.*?)\s*\?>"/', $source, $matches);
        self::assertNotEmpty($matches[1], 'no meta content attributes found — has the snippet moved?');

        foreach ($matches[1] as $expression) {
            self::assertMatchesRegularExpression(
                '/^esc\(.*, \'attr\'\)$/',
                $expression,
                'meta attribute value is not attribute-escaped: ' . $expression
            );
        }
    }

    public function testAttributeEscapingNeutralisesAQuote(): void
    {
        $escaped = esc('[{"content":{"text":"<p>Julia"}}]', 'attr');

        self::assertStringNotContainsString('"', $escaped, 'a raw double quote would close the attribute');
        self::assertStringNotContainsString('<', $escaped);
        self::assertStringContainsString('&quot;', $escaped);
    }
}
