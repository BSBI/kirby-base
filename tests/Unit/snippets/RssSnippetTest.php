<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\snippets;

use BSBI\WebBase\Testing\KirbyContentBuilder;
use BSBI\WebBase\Testing\KirbyTestEnvironment;
use Kirby\Cms\Pages;
use Kirby\Data\Json;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the `rss` snippet (bsbi-web#711).
 *
 * The feed is XML, but the snippet echoed titles, descriptions and URLs raw,
 * so an ampersand in a news story title produced a feed no validator or feed
 * reader would accept. Every dynamic value must be XML-escaped on output.
 */
final class RssSnippetTest extends TestCase
{
    private static KirbyContentBuilder $content;

    public static function setUpBeforeClass(): void
    {
        KirbyTestEnvironment::boot('kirby-base-rss-snippet-' . uniqid());
        self::$content = new KirbyContentBuilder();
    }

    /**
     * Renders the snippet file with the given variables in scope, as
     * Kirby's snippet() helper would.
     *
     * @param array<string, mixed> $data Variables extracted into snippet scope.
     */
    private function render(array $data): string
    {
        $renderer = static function (string $snippetFile, array $snippetData): string {
            extract($snippetData);
            ob_start();
            include $snippetFile;

            return (string)ob_get_clean();
        };

        return $renderer(dirname(__DIR__, 3) . '/snippets/rss.php', $data);
    }

    /**
     * @param array<string, mixed> $postContent
     */
    private function renderFeed(array $postContent): string
    {
        $post = self::$content->page($postContent, 'test-post');

        return $this->render([
            'feedTitle'       => 'News & views',
            'feedLink'        => 'https://example.test/news',
            'feedDescription' => 'News & views from <the> society',
            'feedUrl'         => 'https://example.test/rss/news?page=1&lang=en',
            'posts'           => new Pages([$post]),
        ]);
    }

    /**
     * @return \SimpleXMLElement The parsed feed, failing the test if it is not well-formed.
     */
    private function assertWellFormed(string $xml): \SimpleXMLElement
    {
        libxml_use_internal_errors(true);
        $doc    = simplexml_load_string($xml);
        $errors = array_map(static fn ($error) => trim($error->message), libxml_get_errors());
        libxml_clear_errors();

        $this->assertNotFalse($doc, 'feed is not well-formed XML: ' . implode('; ', $errors));

        return $doc;
    }

    public function testFeedWithAmpersandInTitleIsWellFormedXml(): void
    {
        $xml = $this->renderFeed([
            'title'         => 'Ferns & horsetails <update>',
            'publishedDate' => '2026-08-01',
            'mainContent'   => Json::encode([[
                'type'    => 'text',
                'content' => ['text' => '<p>Sedges &amp; rushes of&nbsp;Britain</p>'],
            ]]),
        ]);

        $doc = $this->assertWellFormed($xml);

        $this->assertSame('News & views', (string)$doc->channel->title);
        $this->assertSame('News & views from <the> society', (string)$doc->channel->description);
        $this->assertSame('Ferns & horsetails <update>', (string)$doc->channel->item[0]->title);
        // &nbsp; is not a valid XML entity: it must reach the reader as the
        // decoded no-break-space character, not leak through as an entity
        $this->assertStringContainsString(
            "Sedges & rushes of\u{00A0}Britain",
            trim((string)$doc->channel->item[0]->description)
        );

        // feed readers de-duplicate on guid; the page URL is a permalink
        $this->assertSame((string)$doc->channel->item[0]->link, (string)$doc->channel->item[0]->guid);
        $this->assertSame('true', (string)$doc->channel->item[0]->guid['isPermaLink']);
    }

    public function testSelfLinkQueryStringIsEscapedInAttribute(): void
    {
        $xml = $this->renderFeed([
            'title'         => 'Plain title',
            'publishedDate' => '2026-08-01',
        ]);

        $this->assertStringContainsString(
            'href="https://example.test/rss/news?page=1&amp;lang=en"',
            $xml
        );
    }
}
