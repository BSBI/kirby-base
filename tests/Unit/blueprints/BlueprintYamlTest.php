<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\blueprints;

use Kirby\Data\Yaml;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Guards the plugin's blueprints against YAML that Kirby's parser reads
 * differently from the YAML spec.
 *
 * Kirby's default YAML handler turns a strip-folded block (`>-`) into a list
 * of lines, and the panel then shows only the first, so help and info text was
 * cut off mid-sentence. Plain `>` (and `|`) parse as one string.
 */
final class BlueprintYamlTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function blueprints(): array
    {
        $root = dirname(__DIR__, 3) . '/blueprints';
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'yml') {
                $path = $file->getPathname();
                $files[substr($path, strlen($root) + 1)] = [$path];
            }
        }
        ksort($files);
        return $files;
    }

    #[DataProvider('blueprints')]
    public function testNoStripFoldedBlocks(string $path): void
    {
        $lines = preg_grep('/:\s*>-\s*$/', (array) file($path));
        $this->assertSame([], $lines, 'Use ">" rather than ">-": Kirby reads ">-" as a list of lines.');
    }

    public function testKirbyReadsAPlainFoldedBlockAsOneString(): void
    {
        $this->assertSame('one two three', trim((string) Yaml::decode("a: >\n  one two\n  three\n")['a']));
    }
}
