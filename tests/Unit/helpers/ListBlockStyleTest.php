<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\ListBlockStyle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * List blocks can number with letters or roman numerals (bsbi-web#786): the
 * block's "List style" sets the HTML type attribute on the outermost <ol>.
 * Bullet lists, nested lists and lists with no style are left as written, and
 * only the allow-listed values ever reach the attribute.
 */
final class ListBlockStyleTest extends TestCase
{
    private const string OL = '<ol><li><p>One</p></li><li><p>Two</p></li></ol>';

    /**
     * @return array<string, array{string, string}>
     */
    public static function styles(): array
    {
        return [
            'lower letters' => ['a', '<ol type="a"><li><p>One</p></li><li><p>Two</p></li></ol>'],
            'capital letters' => ['A', '<ol type="A"><li><p>One</p></li><li><p>Two</p></li></ol>'],
            'lower roman' => ['i', '<ol type="i"><li><p>One</p></li><li><p>Two</p></li></ol>'],
            'capital roman' => ['I', '<ol type="I"><li><p>One</p></li><li><p>Two</p></li></ol>'],
        ];
    }

    #[DataProvider('styles')]
    public function testEachStyleSetsTheTypeOnTheList(string $style, string $expected): void
    {
        $this->assertSame($expected, ListBlockStyle::apply(self::OL, $style));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function noStyle(): array
    {
        return [
            'numbers (the default)' => ['1'],
            'empty, as on every list saved before the option existed' => [''],
            'an unknown value' => ['x'],
            'an attempt to break out of the attribute' => ['a" onclick="alert(1)'],
            'lower-case words' => ['lower-alpha'],
        ];
    }

    #[DataProvider('noStyle')]
    public function testWithoutAKnownStyleTheListIsUnchanged(string $style): void
    {
        $this->assertSame(self::OL, ListBlockStyle::apply(self::OL, $style));
    }

    public function testABulletListIsLeftAlone(): void
    {
        $ul = '<ul><li><p>One</p></li></ul>';
        $this->assertSame($ul, ListBlockStyle::apply($ul, 'a'));
    }

    public function testOnlyTheOutermostListChanges(): void
    {
        $nested = '<ol><li><p>One</p><ol><li><p>Inner</p></li></ol></li></ol>';
        $this->assertSame(
            '<ol type="i"><li><p>One</p><ol><li><p>Inner</p></li></ol></li></ol>',
            ListBlockStyle::apply($nested, 'i')
        );
    }

    public function testLeadingWhitespaceAndExistingAttributesAreKept(): void
    {
        $this->assertSame(
            "\n  <ol start=\"3\" type=\"a\"><li>One</li></ol>",
            ListBlockStyle::apply("\n  <ol start=\"3\"><li>One</li></ol>", 'a')
        );
    }

    public function testAnExistingTypeIsReplacedNotDoubled(): void
    {
        $this->assertSame(
            '<ol type="I"><li>One</li></ol>',
            ListBlockStyle::apply('<ol type="a"><li>One</li></ol>', 'I')
        );
    }

    public function testEmptyHtmlStaysEmpty(): void
    {
        $this->assertSame('', ListBlockStyle::apply('', 'a'));
    }

    public function testTheOptionsMatchTheAllowList(): void
    {
        $this->assertSame(['1', 'a', 'A', 'i', 'I'], array_map('strval', array_keys(ListBlockStyle::OPTIONS)));
    }
}
