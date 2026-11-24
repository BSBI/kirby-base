<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\KirbyFieldReader;
use BSBI\WebBase\helpers\UuidResolver;
use BSBI\WebBase\Testing\KirbyTestEnvironment;
use Kirby\Cms\App;
use Kirby\Cms\Block;
use Kirby\Cms\Files;
use Kirby\Cms\Page;
use Kirby\Content\Content;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Link fields (a page_link's redirect_link, say) go through UuidResolver too
 * (bsbi-web#941). A `page://` or `file://` link that resolves to nothing is a broken
 * link, and must never fall through to `Field::toUrl()`: Kirby core then walks the
 * whole site index looking for it, on every request, because it never caches a miss.
 */
final class KirbyFieldReaderLinkTest extends TestCase
{
    private static App $kirby;
    private static KirbyFieldReader $reader;

    public static function setUpBeforeClass(): void
    {
        self::$kirby = KirbyTestEnvironment::bootWithContent(
            dirname(__DIR__, 2) . '/fixtures/link-content',
            'kirby-base-field-reader-link',
            [
                'options' => ['cacheName' => 'bsbi', 'cache' => ['bsbi' => true, 'uuid' => true]],
                'pageModels' => ['default' => ContentReadCountingPage::class],
                'snippets' => ['blocks/button' => dirname(__DIR__, 3) . '/snippets/blocks/button.php'],
            ]
        );
        self::$reader = new KirbyFieldReader(self::$kirby, self::$kirby->site());
    }

    protected function setUp(): void
    {
        (new UuidResolver(self::$kirby))->forgetMisses();
        ContentReadCountingPage::$reads = [];
    }

    public function testLinkTypes(): void
    {
        $home = self::home();

        self::assertSame('page', self::$reader->getLinkFieldType($home, 'pagelink'));
        self::assertSame('url', self::$reader->getLinkFieldType($home, 'filelink'));
        self::assertSame('url', self::$reader->getLinkFieldType($home, 'urllink'));
        self::assertSame('url', self::$reader->getLinkFieldType($home, 'maillink'));
        self::assertSame('', self::$reader->getLinkFieldType($home, 'badpagelink'));
        self::assertSame('', self::$reader->getLinkFieldType($home, 'badpagelinklist'));
        self::assertSame('', self::$reader->getLinkFieldType($home, 'badfilelink'));
    }

    public function testLinkUrls(): void
    {
        $home = self::home();

        self::assertSame('/other', self::path(self::$reader->getPageFieldAsUrl($home, 'pagelink')));
        self::assertStringEndsWith('/other#section', self::$reader->getPageFieldAsUrl($home, 'anchorlink'));
        self::assertStringEndsWith('/pic.svg', self::$reader->getPageFieldAsUrl($home, 'filelink'));
        self::assertSame('https://example.org/somewhere', self::$reader->getPageFieldAsUrl($home, 'urllink'));
        self::assertSame('mailto:someone@example.org', self::$reader->getPageFieldAsUrl($home, 'maillink'));
        self::assertSame('', self::$reader->getPageFieldAsUrl($home, 'badpagelink'));
        self::assertSame('', self::$reader->getPageFieldAsUrl($home, 'badfilelink'));
    }

    /**
     * Link-list items (an error page's suggested links, a home page's hero links) use the
     * structure's link field (bsbi-web#942). It always returned '' before: a `return`
     * in `finally` replaced the one in `try`.
     */
    public function testStructureLinkUrls(): void
    {
        $items = self::$reader->getPageFieldAsStructure(self::home(), 'structurelinks');
        $urls = [];
        foreach ($items as $item) {
            $urls[] = self::$reader->getStructureFieldAsLinkUrl($item, 'url');
        }

        self::assertSame('/other', self::path($urls[0]));
        self::assertSame('https://example.org/elsewhere', $urls[1]);
        self::assertSame('', $urls[2], 'a broken page link');
        self::assertSame('', $urls[3], 'an empty link');
    }

    /**
     * A walk reads every page's content (page UUIDs) or files (file UUIDs); "other" is
     * read for nothing else here, so any read of it means the index was walked.
     */
    #[DataProvider('brokenLinks')]
    public function testAKnownBrokenLinkDoesNotWalkTheIndex(string $field): void
    {
        $home = self::home();
        // The first lookup may walk once, to learn that the UUID is a miss.
        self::$reader->getLinkFieldType($home, $field);
        ContentReadCountingPage::$reads = [];

        self::assertSame('', self::$reader->getLinkFieldType($home, $field));
        self::assertSame('', self::$reader->getPageFieldAsUrl($home, $field));
        self::assertSame([], ContentReadCountingPage::$reads, 'the site index was walked');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function brokenLinks(): array
    {
        return [
            'page link' => ['badpagelink'],
            'page link stored as a list' => ['badpagelinklist'],
            'file link' => ['badfilelink'],
        ];
    }

    /**
     * Text blocks turn permalinks into URLs. Intact ones resolve as Kirby core does;
     * a broken one is left as it was.
     */
    public function testTextBlockPermalinks(): void
    {
        $html = self::$reader->getHTMLfromBlock(self::textBlock(
            '<p><a href="/@/page/pagebbbbbbbbbbbb">a</a> <a href="page://pagebbbbbbbbbbbb">b</a>'
            . ' <a href="/@/file/fileaaaaaaaaaaaa">c</a> <img src="/@/file/fileaaaaaaaaaaaa" alt="">'
            . ' <a href="https://example.org/x">d</a> <a href="/relative">e</a>'
            . ' <a href="/@/page/nosuchpage000000">f</a> <a href="/@/file/nosuchfile000000">g</a></p>'
        ));

        self::assertSame(2, substr_count($html, '/other"'), $html);
        self::assertSame(2, substr_count($html, '/pic.svg"'), $html);
        self::assertStringContainsString('href="https://example.org/x"', $html);
        self::assertStringContainsString('href="/relative"', $html);
        self::assertStringContainsString('href="/@/page/nosuchpage000000"', $html);
        self::assertStringContainsString('href="/@/file/nosuchfile000000"', $html);
    }

    /**
     * @see testAKnownBrokenLinkDoesNotWalkTheIndex()
     */
    public function testAKnownBrokenPermalinkInATextBlockDoesNotWalkTheIndex(): void
    {
        $block = self::textBlock('<p><a href="/@/page/nosuchpage000000">a</a> <img src="/@/file/nosuchfile000000" alt=""></p>');
        self::$reader->getHTMLfromBlock($block);
        ContentReadCountingPage::$reads = [];

        self::$reader->getHTMLfromBlock($block);
        self::assertSame([], ContentReadCountingPage::$reads, 'the site index was walked');
    }

    /**
     * The button block snippet: a broken link renders an empty href, as before,
     * without walking the index once the miss is known.
     */
    public function testButtonBlockLinks(): void
    {
        self::assertStringContainsString('/other"', self::button('page://pagebbbbbbbbbbbb'));
        self::assertStringContainsString('href="https://example.org/x"', self::button('https://example.org/x'));

        self::assertStringContainsString('href=""', self::button('page://nosuchpage000000'));
        ContentReadCountingPage::$reads = [];
        self::assertStringContainsString('href=""', self::button('page://nosuchpage000000'));
        self::assertSame([], ContentReadCountingPage::$reads, 'the site index was walked');
    }

    private static function button(string $link): string
    {
        return (new Block([
            'type' => 'button',
            'content' => ['link' => $link, 'text' => 'Go'],
            'parent' => self::home(),
        ]))->toHtml();
    }

    private static function textBlock(string $html): Block
    {
        return new Block(['type' => 'text', 'content' => ['text' => $html], 'parent' => self::home()]);
    }

    private static function home(): Page
    {
        $home = self::$kirby->page('home');
        self::assertInstanceOf(Page::class, $home);

        return $home;
    }

    private static function path(string $url): string
    {
        return (string) parse_url($url, PHP_URL_PATH);
    }
}

/**
 * Counts content and file reads of every page but home, the page whose fields are read.
 */
class ContentReadCountingPage extends Page
{
    /** @var list<string> */
    public static array $reads = [];

    public function content(string|null $languageCode = null): Content
    {
        if ($this->id() !== 'home') {
            self::$reads[] = $this->id();
        }

        return parent::content($languageCode);
    }

    public function files(): Files
    {
        if ($this->id() !== 'home') {
            self::$reads[] = $this->id() . ' files';
        }

        return parent::files();
    }
}
