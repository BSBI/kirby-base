<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\PostedText;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PostedText — posted form input made safe for esc(), which throws on
 * invalid UTF-8 (seen on live from a spam bot posting mangled emoji to /feedback).
 */
final class PostedTextTest extends TestCase
{
    public function testInvalidUtf8IsReplacedAndSafeToEscape(): void
    {
        $scrubbed = PostedText::scrub("Attention \xF0\x9F music \xFF lovers");

        $this->assertIsString($scrubbed);
        $this->assertTrue(mb_check_encoding($scrubbed, 'UTF-8'));
        $this->assertSame('Attention ? music ? lovers', $scrubbed);
        // Before scrubbing, this call threw "String to be escaped was not valid UTF-8".
        $this->assertNotSame('', esc($scrubbed, 'attr'));
    }

    public function testValidTextIsUnchanged(): void
    {
        $text = "Cymraeg: ŵ ŷ — 🌱 <b>&</b>";
        $this->assertSame($text, PostedText::scrub($text));
    }

    public function testArraysAreScrubbedRecursivelyIncludingKeys(): void
    {
        $scrubbed = PostedText::scrub([
            'feedback' => "bad \xFF",
            "k\xFF" => ['nested' => "x\xF0\x9F"],
            'count' => 3,
        ]);

        $this->assertSame([
            'feedback' => 'bad ?',
            'k?' => ['nested' => 'x?'],
            'count' => 3,
        ], $scrubbed);
    }

    public function testNonStringValuesAreReturnedUnchanged(): void
    {
        $this->assertNull(PostedText::scrub(null));
        $this->assertSame(5, PostedText::scrub(5));
        $this->assertFalse(PostedText::scrub(false));
    }
}
