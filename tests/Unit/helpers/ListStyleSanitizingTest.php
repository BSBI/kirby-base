<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\ListStyleSanitizing;
use Kirby\Sane\Sane;
use PHPUnit\Framework\TestCase;

/**
 * Since Kirby 5.6 the list field runs its value through Sane's HTML handler, which
 * drops every <ol> attribute; the lettered and roman list styles (bsbi-web#786) are
 * stored as <ol type="…">, so a save in the Panel turned them back into numbers.
 * (Sane::sanitizeProseMirrorFields() in 5.6 is Sane::sanitize($html, 'html').)
 */
final class ListStyleSanitizingTest extends TestCase
{
    protected function setUp(): void
    {
        ListStyleSanitizing::allow();
    }

    public function testListStyleTypeSurvivesTheListFieldSanitizer(): void
    {
        foreach (['a', 'A', 'i', 'I', '1'] as $type) {
            $html = '<ol type="' . $type . '"><li><p>Convert one</p></li></ol>';
            $this->assertStringContainsString(
                '<ol type="' . $type . '">',
                Sane::sanitize($html, 'html'),
                "type=\"$type\""
            );
        }
    }

    public function testOtherOrderedListAttributesAreStillRemoved(): void
    {
        $clean = Sane::sanitize('<ol type="a" onclick="alert(1)" style="color:red"><li>x</li></ol>', 'html');

        $this->assertStringContainsString('type="a"', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('style', $clean);
    }

    public function testAllowingTwiceIsHarmless(): void
    {
        ListStyleSanitizing::allow();
        ListStyleSanitizing::allow();

        $this->assertStringContainsString('<ol type="i">', Sane::sanitize('<ol type="i"><li>x</li></ol>', 'html'));
    }
}
