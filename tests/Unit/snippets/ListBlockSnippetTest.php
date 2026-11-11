<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\snippets;

use BSBI\WebBase\Testing\KirbyContentBuilder;
use BSBI\WebBase\Testing\KirbyTestEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * The `blocks/list` snippet (bsbi-web#786) renders the Writer's list with the
 * block's "List style" on the outermost <ol>. Lists saved before the option
 * existed (no listStyle) render exactly as Kirby's own snippet did.
 */
final class ListBlockSnippetTest extends TestCase
{
    private static KirbyContentBuilder $content;

    public static function setUpBeforeClass(): void
    {
        $fixture = sys_get_temp_dir() . '/kirby-base-list-fixture-' . uniqid();
        mkdir($fixture, 0777, true);
        file_put_contents($fixture . '/site.txt', "Title: Test Site\n");
        KirbyTestEnvironment::bootWithContent($fixture, 'kirby-base-list-snippet');
        self::$content = new KirbyContentBuilder();
    }

    /**
     * @param array<string, mixed> $content
     */
    private function render(array $content): string
    {
        $block = self::$content->block(['type' => 'list', 'content' => $content]);
        $renderer = static function (string $snippetFile, array $snippetData): string {
            extract($snippetData);
            ob_start();
            include $snippetFile;
            return (string) ob_get_clean();
        };
        return $renderer(dirname(__DIR__, 3) . '/snippets/blocks/list.php', ['block' => $block]);
    }

    public function testALetteredStyleSetsTheType(): void
    {
        $html = $this->render(['text' => '<ol><li><p>One</p></li></ol>', 'listStyle' => 'a']);
        $this->assertSame('<ol type="a"><li><p>One</p></li></ol>', trim($html));
    }

    public function testAListSavedBeforeTheOptionIsUnchanged(): void
    {
        $html = $this->render(['text' => '<ol><li><p>One</p></li></ol>']);
        $this->assertSame('<ol><li><p>One</p></li></ol>', trim($html));
    }

    public function testABulletListIsUnchanged(): void
    {
        $html = $this->render(['text' => '<ul><li><p>One</p></li></ul>', 'listStyle' => 'i']);
        $this->assertSame('<ul><li><p>One</p></li></ul>', trim($html));
    }

    public function testTheSnippetIsRegisteredForEverySite(): void
    {
        $snippets = require dirname(__DIR__, 3) . '/snippets.php';
        $this->assertSame(dirname(__DIR__, 3) . '/snippets/blocks/list.php', $snippets['blocks/list'] ?? null);
    }

    public function testTheBlueprintOffersTheAllowListedStyles(): void
    {
        $yaml = \Kirby\Data\Yaml::decode((string) file_get_contents(dirname(__DIR__, 3) . '/blueprints/blocks/list.yml'));
        $field = $yaml['fields']['listStyle'] ?? null;
        $this->assertIsArray($field);
        $this->assertSame('select', $field['type']);
        $this->assertSame(
            array_map('strval', array_keys(\BSBI\WebBase\helpers\ListBlockStyle::OPTIONS)),
            array_map('strval', array_keys($field['options']))
        );
    }
}
