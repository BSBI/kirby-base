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

    /**
     * Form blocks and the content field their panel title shows.
     *
     * @return array<string, array{string, string}>
     */
    public static function formBlockTitles(): array
    {
        $titles = [];
        foreach (['textbox', 'textarea', 'radio-group', 'checkbox-group', 'select', 'likert', 'rating-matrix'] as $type) {
            $titles['form-' . $type] = ['form-' . $type, '{{ label }}'];
        }
        $titles['form-info'] = ['form-info', '{{ text }}'];
        $titles['form-section-inline'] = ['form-section-inline', '{{ title }}'];
        $titles['form-section-ref'] = ['form-section-ref', '{{ title }}'];
        return $titles;
    }

    /**
     * A form block's panel title shows its question (or title) beside the block
     * name, so editors see the order of their questions without opening each.
     */
    #[DataProvider('formBlockTitles')]
    public function testFormBlocksShowTheirContentInTheirTitle(string $block, string $template): void
    {
        $blueprint = Yaml::decode((string) file_get_contents(dirname(__DIR__, 3) . '/blueprints/blocks/' . $block . '.yml'));

        $this->assertSame($template, $blueprint['label'] ?? null);
        $this->assertNotSame('', $blueprint['name'] ?? '', 'the name stays as the block type');
    }

    public function testKirbyReadsAPlainFoldedBlockAsOneString(): void
    {
        $this->assertSame('one two three', trim((string) Yaml::decode("a: >\n  one two\n  three\n")['a']));
    }
}
