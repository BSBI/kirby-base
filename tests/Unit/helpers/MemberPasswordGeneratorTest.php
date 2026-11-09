<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\MemberPasswordGenerator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Passwords people can read out and type: three words from a list, joined by
 * one separator (bsbi-web#869). The default list is plant genera.
 */
final class MemberPasswordGeneratorTest extends TestCase
{
    public function testPasswordIsThreeListedWordsWithOneSeparator(): void
    {
        $generator = new MemberPasswordGenerator();

        for ($i = 0; $i < 50; $i++) {
            $password = $generator->generate();
            $this->assertMatchesRegularExpression('/^[A-Za-z]+([-_*])[A-Za-z]+\1[A-Za-z]+$/', $password);
            foreach (preg_split('/[-_*]/', $password) ?: [] as $word) {
                $this->assertContains($word, MemberPasswordGenerator::DEFAULT_WORDS);
            }
            $this->assertGreaterThanOrEqual(8, strlen($password), 'Kirby refuses passwords under 8 characters');
        }
    }

    public function testDefaultListIsPlantGenera(): void
    {
        $this->assertContains('Allium', MemberPasswordGenerator::DEFAULT_WORDS);
        $this->assertGreaterThanOrEqual(200, count(array_unique(MemberPasswordGenerator::DEFAULT_WORDS)));
    }

    public function testSiteSuppliedWordsAreUsed(): void
    {
        $password = (new MemberPasswordGenerator(['Moss', 'Fern']))->generate();

        foreach (preg_split('/[-_*]/', $password) ?: [] as $word) {
            $this->assertContains($word, ['Moss', 'Fern']);
        }
    }

    public function testWordsTooShortForKirbysMinimumAreRefused(): void
    {
        // Three words and two separators must reach Kirby's 8 characters,
        // so every word needs at least 2 letters.
        $this->assertSame(8, strlen((new MemberPasswordGenerator(['Ab']))->generate()));

        $this->expectException(InvalidArgumentException::class);
        new MemberPasswordGenerator(['Moss', 'A']);
    }

    public function testEveryWordCanBeChosen(): void
    {
        $generator = new MemberPasswordGenerator(['Moss', 'Fern', 'Sedge']);
        $seen = [];

        for ($i = 0; $i < 200; $i++) {
            foreach (preg_split('/[-_*]/', $generator->generate()) ?: [] as $word) {
                $seen[$word] = true;
            }
        }

        $this->assertEqualsCanonicalizing(['Moss', 'Fern', 'Sedge'], array_keys($seen));
    }

    public function testEmptyListIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MemberPasswordGenerator([]);
    }
}
