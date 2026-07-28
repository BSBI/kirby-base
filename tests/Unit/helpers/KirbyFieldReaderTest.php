<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\KirbyFieldReader;
use BSBI\WebBase\helpers\KirbyRetrievalException;
use BSBI\WebBase\models\WebPageLink;
use DateTime;
use Kirby\Cms\App;
use Kirby\Cms\Block;
use Kirby\Cms\Page;
use Kirby\Cms\Structure;
use Kirby\Cms\StructureObject;
use Kirby\Data\Json;
use Kirby\Data\Yaml;
use PHPUnit\Framework\TestCase;

/**
 * Tests for KirbyFieldReader.
 *
 * A minimal Kirby App instance is created once for the test class. Pages are
 * built in-memory using Page::factory() so no filesystem content is needed for
 * page/structure/block field tests.
 *
 * Site field tests write a temporary site.txt so that Kirby's site content API
 * resolves correctly; the temp directory is cleaned up after each test.
 */
final class KirbyFieldReaderTest extends TestCase
{
    private static App $kirby;
    private static KirbyFieldReader $reader;
    private static string $tmpDir;

    public static function setUpBeforeClass(): void
    {
        self::$tmpDir = sys_get_temp_dir() . '/kirby-field-reader-test';
        $contentDir = self::$tmpDir . '/content';

        if (!is_dir($contentDir)) {
            mkdir($contentDir, 0777, true);
        }

        // Site content used by site-field tests.
        // Kirby 5 content files separate fields with `----` on its own line.
        file_put_contents(
            $contentDir . '/site.txt',
            "Title: Test Site\n\n----\n\nsitestringfield: Site Value\n\n----\n\nsiteboolfield: true\n\n----\n\nsitearrayfield: one, two, three\n"
        );

        self::$kirby = new App([
            'roots' => [
                'index'   => self::$tmpDir,
                'content' => $contentDir,
            ],
            'options' => [],
            'users' => [
                ['email' => 'jane@bsbi.org', 'name' => 'Jane Doe', 'content' => ['jobTitle' => 'Records Officer']],
                ['email' => 'bob@bsbi.org', 'name' => 'Bob Lee'],
            ],
        ]);

        self::$reader = new KirbyFieldReader(self::$kirby, self::$kirby->site());
    }

    public static function tearDownAfterClass(): void
    {
        // Remove temp site.txt but leave directory (harmless)
        @unlink(self::$tmpDir . '/content/site.txt');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makePage(array $content = []): Page
    {
        return Page::factory([
            'slug'    => 'test-' . uniqid(),
            'content' => $content,
        ]);
    }

    // =========================================================================
    // PAGE FIELDS — getPageField
    // =========================================================================

    public function testGetPageFieldReturnsFieldWhenPresent(): void
    {
        $page = $this->makePage(['title' => 'Hello']);
        $field = self::$reader->getPageField($page, 'title');

        $this->assertSame('Hello', $field->toString());
    }

    public function testGetPageFieldThrowsWhenEmpty(): void
    {
        $page = $this->makePage();

        $this->expectException(KirbyRetrievalException::class);
        self::$reader->getPageField($page, 'missing');
    }

    // =========================================================================
    // PAGE FIELDS — isPageFieldNotEmpty
    // =========================================================================

    public function testIsPageFieldNotEmptyReturnsTrueWhenPopulated(): void
    {
        $page = $this->makePage(['myfield' => 'value']);

        $this->assertTrue(self::$reader->isPageFieldNotEmpty($page, 'myfield'));
    }

    public function testIsPageFieldNotEmptyReturnsFalseWhenMissing(): void
    {
        $page = $this->makePage();

        $this->assertFalse(self::$reader->isPageFieldNotEmpty($page, 'missing'));
    }

    // =========================================================================
    // PAGE FIELDS — string
    // =========================================================================

    public function testGetPageFieldAsStringReturnsValue(): void
    {
        $page = $this->makePage(['myfield' => 'Hello World']);

        $this->assertSame('Hello World', self::$reader->getPageFieldAsString($page, 'myfield'));
    }

    public function testGetPageFieldAsStringReturnsDefaultWhenMissing(): void
    {
        $page = $this->makePage();

        $this->assertSame('fallback', self::$reader->getPageFieldAsString($page, 'missing', false, 'fallback'));
    }

    public function testGetPageFieldAsStringReturnsEmptyStringByDefault(): void
    {
        $page = $this->makePage();

        $this->assertSame('', self::$reader->getPageFieldAsString($page, 'missing'));
    }

    public function testGetPageFieldAsStringThrowsWhenRequiredAndMissing(): void
    {
        $page = $this->makePage();

        $this->expectException(KirbyRetrievalException::class);
        self::$reader->getPageFieldAsString($page, 'missing', true);
    }

    // =========================================================================
    // PAGE FIELDS — stringWithFallback
    // =========================================================================

    public function testGetPageFieldAsStringWithFallbackReturnsValue(): void
    {
        $page = $this->makePage(['myfield' => 'Real Value']);

        $this->assertSame('Real Value', self::$reader->getPageFieldAsStringWithFallback($page, 'myfield', 'fallback'));
    }

    public function testGetPageFieldAsStringWithFallbackReturnsFallbackWhenMissing(): void
    {
        $page = $this->makePage();

        $this->assertSame('fallback', self::$reader->getPageFieldAsStringWithFallback($page, 'missing', 'fallback'));
    }

    // =========================================================================
    // PAGE FIELDS — int
    // =========================================================================

    public function testGetPageFieldAsIntReturnsValue(): void
    {
        $page = $this->makePage(['count' => '42']);

        $this->assertSame(42, self::$reader->getPageFieldAsInt($page, 'count'));
    }

    public function testGetPageFieldAsIntReturnsDefaultWhenMissing(): void
    {
        $page = $this->makePage();

        $this->assertSame(0, self::$reader->getPageFieldAsInt($page, 'missing'));
        $this->assertSame(99, self::$reader->getPageFieldAsInt($page, 'missing', false, 99));
    }

    public function testGetPageFieldAsIntThrowsWhenRequiredAndMissing(): void
    {
        $page = $this->makePage();

        $this->expectException(KirbyRetrievalException::class);
        self::$reader->getPageFieldAsInt($page, 'missing', true);
    }

    // =========================================================================
    // PAGE FIELDS — float
    // =========================================================================

    public function testGetPageFieldAsFloatReturnsValue(): void
    {
        $page = $this->makePage(['price' => '9.99']);

        $this->assertEqualsWithDelta(9.99, self::$reader->getPageFieldAsFloat($page, 'price'), 0.001);
    }

    public function testGetPageFieldAsFloatReturnsZeroWhenMissing(): void
    {
        $page = $this->makePage();

        $this->assertSame(0.0, self::$reader->getPageFieldAsFloat($page, 'missing'));
    }

    public function testGetPageFieldAsFloatThrowsWhenRequiredAndMissing(): void
    {
        $page = $this->makePage();

        $this->expectException(KirbyRetrievalException::class);
        self::$reader->getPageFieldAsFloat($page, 'missing', true);
    }

    // =========================================================================
    // PAGE FIELDS — bool
    // =========================================================================

    public function testGetPageFieldAsBoolReturnsTrueForTrueValue(): void
    {
        $page = $this->makePage(['active' => 'true']);

        $this->assertTrue(self::$reader->getPageFieldAsBool($page, 'active'));
    }

    public function testGetPageFieldAsBoolReturnsFalseForFalseValue(): void
    {
        $page = $this->makePage(['active' => 'false']);

        $this->assertFalse(self::$reader->getPageFieldAsBool($page, 'active'));
    }

    public function testGetPageFieldAsBoolReturnsFalseByDefault(): void
    {
        $page = $this->makePage();

        $this->assertFalse(self::$reader->getPageFieldAsBool($page, 'missing'));
    }

    public function testGetPageFieldAsBoolReturnsCustomDefaultWhenMissing(): void
    {
        $page = $this->makePage();

        $this->assertTrue(self::$reader->getPageFieldAsBool($page, 'missing', false, true));
    }

    public function testGetPageFieldAsBoolThrowsWhenRequiredAndMissing(): void
    {
        $page = $this->makePage();

        $this->expectException(KirbyRetrievalException::class);
        self::$reader->getPageFieldAsBool($page, 'missing', true);
    }

    // =========================================================================
    // PAGE FIELDS — yesNo
    // =========================================================================

    public function testGetPageFieldAsYesNoReturnsYesForTrue(): void
    {
        $page = $this->makePage(['flag' => 'true']);

        $this->assertSame('Yes', self::$reader->getPageFieldAsYesNo($page, 'flag'));
    }

    public function testGetPageFieldAsYesNoReturnsNoForFalse(): void
    {
        $page = $this->makePage(['flag' => 'false']);

        $this->assertSame('No', self::$reader->getPageFieldAsYesNo($page, 'flag'));
    }

    public function testGetPageFieldAsYesNoReturnsNOWhenMissingAndNotRequired(): void
    {
        $page = $this->makePage();

        $this->assertSame('NO', self::$reader->getPageFieldAsYesNo($page, 'missing'));
    }

    public function testGetPageFieldAsYesNoThrowsWhenRequiredAndMissing(): void
    {
        $page = $this->makePage();

        $this->expectException(KirbyRetrievalException::class);
        self::$reader->getPageFieldAsYesNo($page, 'missing', true);
    }

    // =========================================================================
    // PAGE FIELDS — DateTime
    // =========================================================================

    public function testGetPageFieldAsDateTimeReturnsDateTimeForValidDate(): void
    {
        $page = $this->makePage(['published' => '2024-06-15']);
        $dt = self::$reader->getPageFieldAsDateTime($page, 'published');

        $this->assertInstanceOf(DateTime::class, $dt);
        $this->assertSame('2024-06-15', $dt->format('Y-m-d'));
    }

    public function testGetPageFieldAsDateTimeReturnsNullWhenMissing(): void
    {
        $page = $this->makePage();

        $this->assertNull(self::$reader->getPageFieldAsDateTime($page, 'missing'));
    }

    public function testGetPageFieldAsDateTimeThrowsWhenRequiredAndMissing(): void
    {
        $page = $this->makePage();

        $this->expectException(KirbyRetrievalException::class);
        self::$reader->getPageFieldAsDateTime($page, 'missing', true);
    }

    public function testGetPageFieldAsRequiredDateTimeReturnsDateTime(): void
    {
        $page = $this->makePage(['published' => '2024-01-01']);
        $dt = self::$reader->getPageFieldAsRequiredDateTime($page, 'published');

        $this->assertInstanceOf(DateTime::class, $dt);
        $this->assertSame('2024-01-01', $dt->format('Y-m-d'));
    }

    public function testGetPageFieldAsRequiredDateTimeThrowsWhenMissing(): void
    {
        $page = $this->makePage();

        $this->expectException(KirbyRetrievalException::class);
        self::$reader->getPageFieldAsRequiredDateTime($page, 'missing');
    }

    // =========================================================================
    // PAGE FIELDS — time
    // =========================================================================

    public function testGetPageFieldAsTimeReturnsFormattedTime(): void
    {
        $page = $this->makePage(['starts' => '14:30']);
        $time = self::$reader->getPageFieldAsTime($page, 'starts');

        // Kirby stores dates as timestamps; the exact format depends on timezone
        // but the result should be a non-empty string
        $this->assertNotEmpty($time);
    }

    public function testGetPageFieldAsTimeReturnsEmptyStringWhenMissing(): void
    {
        $page = $this->makePage();

        $this->assertSame('', self::$reader->getPageFieldAsTime($page, 'missing'));
    }

    // =========================================================================
    // PAGE FIELDS — structure
    // =========================================================================

    public function testGetPageFieldAsStructureReturnsStructure(): void
    {
        $yaml = Yaml::encode([['name' => 'Alice'], ['name' => 'Bob']]);
        $page = $this->makePage(['items' => $yaml]);
        $structure = self::$reader->getPageFieldAsStructure($page, 'items');

        $this->assertInstanceOf(Structure::class, $structure);
        $this->assertCount(2, $structure);
    }

    public function testGetPageFieldAsStructureReturnsEmptyStructureWhenMissing(): void
    {
        $page = $this->makePage();
        $structure = self::$reader->getPageFieldAsStructure($page, 'missing');

        $this->assertInstanceOf(Structure::class, $structure);
        $this->assertCount(0, $structure);
    }

    // =========================================================================
    // PAGE FIELDS — url
    // =========================================================================

    public function testGetPageFieldAsUrlReturnsValue(): void
    {
        $page = $this->makePage(['link' => 'https://example.com']);

        $this->assertSame('https://example.com', self::$reader->getPageFieldAsUrl($page, 'link'));
    }

    public function testGetPageFieldAsUrlReturnsEmptyStringWhenMissing(): void
    {
        $page = $this->makePage();

        $this->assertSame('', self::$reader->getPageFieldAsUrl($page, 'missing'));
    }

    // =========================================================================
    // PAGE FIELDS — array
    // =========================================================================

    public function testGetPageFieldAsArrayReturnsSplitValues(): void
    {
        $page = $this->makePage(['tags' => 'php, kirby, cms']);
        $tags = self::$reader->getPageFieldAsArray($page, 'tags');

        $this->assertSame(['php', 'kirby', 'cms'], $tags);
    }

    public function testGetPageFieldAsArrayReturnsEmptyArrayWhenMissing(): void
    {
        $page = $this->makePage();

        $this->assertSame([], self::$reader->getPageFieldAsArray($page, 'missing'));
    }

    public function testGetPageFieldAsArrayThrowsWhenRequiredAndMissing(): void
    {
        $page = $this->makePage();

        $this->expectException(KirbyRetrievalException::class);
        self::$reader->getPageFieldAsArray($page, 'missing', true);
    }

    // =========================================================================
    // PAGE FIELDS — blocks
    // =========================================================================

    public function testGetPageFieldAsBlocksReturnsBlocksCollection(): void
    {
        $blocks = [
            ['type' => 'text', 'content' => ['text' => 'Hello']],
            ['type' => 'heading', 'content' => ['text' => 'Title', 'level' => 'h2']],
        ];
        $page = $this->makePage(['content' => Json::encode($blocks)]);
        $result = self::$reader->getPageFieldAsBlocks($page, 'content');

        $this->assertCount(2, $result);
    }

    public function testGetPageFieldAsBlocksHtmlReturnsHtmlString(): void
    {
        $blocks = [['type' => 'text', 'content' => ['text' => '<p>Hello</p>']]];
        $page = $this->makePage(['content' => Json::encode($blocks)]);
        $html = self::$reader->getPageFieldAsBlocksHtml($page, 'content');

        $this->assertStringContainsString('Hello', $html);
    }

    public function testGetPageFieldAsBlocksHtmlReturnsEmptyStringWhenMissing(): void
    {
        $page = $this->makePage();

        $this->assertSame('', self::$reader->getPageFieldAsBlocksHtml($page, 'missing'));
    }

    public function testGetPageFieldAsBlocksHtmlHonoursExcerptLength(): void
    {
        $longText = str_repeat('word ', 200); // ~1000 chars
        $blocks = [['type' => 'text', 'content' => ['text' => "<p>$longText</p>"]]];
        $page = $this->makePage(['content' => Json::encode($blocks)]);

        $short = self::$reader->getPageFieldAsBlocksHtml($page, 'content', false, 50);
        $long  = self::$reader->getPageFieldAsBlocksHtml($page, 'content', false, 400);

        // The requested length must be respected, not a hard-coded cap.
        $this->assertLessThanOrEqual(60, strlen($short), 'A 50-char excerpt should be short');
        $this->assertGreaterThan(300, strlen($long), 'A 400-char excerpt should deliver more text');
        $this->assertGreaterThan(strlen($short), strlen($long), 'A larger length must yield more text');
    }

    public function testGetPageFieldAsBlocksHtmlExcerptSeparatesAdjacentBlocks(): void
    {
        // Two text blocks (or two paragraphs) must not run together when the
        // excerpt strips the tags between them: "...ends here.Second..." is wrong.
        $blocks = [
            ['type' => 'text', 'content' => ['text' => '<p>First paragraph ends here.</p>']],
            ['type' => 'text', 'content' => ['text' => '<p>Second paragraph starts here.</p>']],
        ];
        $page = $this->makePage(['content' => Json::encode($blocks)]);
        $excerpt = self::$reader->getPageFieldAsBlocksHtml($page, 'content', false, 200);

        $this->assertStringContainsString('here. Second', $excerpt);
        $this->assertStringNotContainsString('here.Second', $excerpt);
    }

    public function testGetPageFieldTextBlocksAsExcerptReturnsShortened(): void
    {
        $longText = str_repeat('word ', 100);
        $blocks = [['type' => 'text', 'content' => ['text' => "<p>$longText</p>"]]];
        $page = $this->makePage(['content' => Json::encode($blocks)]);
        $excerpt = self::$reader->getPageFieldTextBlocksAsExcerpt($page, 'content', 50);

        $this->assertLessThanOrEqual(60, strlen($excerpt)); // Kirby adds ellipsis
    }

    public function testGetPageFieldTextBlocksAsExcerptReturnsEmptyForZeroLength(): void
    {
        $page = $this->makePage(['content' => Json::encode([['type' => 'text', 'content' => ['text' => 'hello']]])]);

        $this->assertSame('', self::$reader->getPageFieldTextBlocksAsExcerpt($page, 'content', 0));
    }

    // =========================================================================
    // PAGE FIELDS — getLinkFieldType
    // =========================================================================

    public function testGetLinkFieldTypeReturnsUrlForUrlValue(): void
    {
        $page = $this->makePage(['mylink' => 'https://example.com']);

        $this->assertSame('url', self::$reader->getLinkFieldType($page, 'mylink'));
    }

    public function testGetLinkFieldTypeThrowsWhenFieldMissing(): void
    {
        $page = $this->makePage();

        $this->expectException(KirbyRetrievalException::class);
        self::$reader->getLinkFieldType($page, 'missing');
    }

    // =========================================================================
    // PAGE FIELDS — getPageTitle / getPageUrl / getPageType
    // =========================================================================

    public function testGetPageTitleReturnsTitle(): void
    {
        $page = $this->makePage(['title' => 'My Page Title']);

        $this->assertSame('My Page Title', self::$reader->getPageTitle($page));
    }

    public function testGetPageUrlReturnsUrl(): void
    {
        $page = $this->makePage(['title' => 'My Page']);

        $this->assertIsString(self::$reader->getPageUrl($page));
    }

    public function testGetPageTypeReturnsTemplateName(): void
    {
        $page = $this->makePage(['title' => 'Test']);

        $this->assertIsString(self::$reader->getPageType($page));
        $this->assertNotEmpty(self::$reader->getPageType($page));
    }

    // =========================================================================
    // PAGE FIELDS — file and files
    // =========================================================================

    public function testGetPageFieldAsFileReturnsNullWhenNoFileReferenced(): void
    {
        $page = $this->makePage(['image' => 'nonexistent.jpg']);
        $file = self::$reader->getPageFieldAsFile($page, 'image');

        // toFile() returns null when the file cannot be resolved
        $this->assertNull($file);
    }

    // =========================================================================
    // SITE FIELDS
    // =========================================================================

    public function testGetSiteFieldAsStringReturnsValue(): void
    {
        $value = self::$reader->getSiteFieldAsString('sitestringfield');

        $this->assertSame('Site Value', $value);
    }

    public function testGetSiteFieldAsStringReturnsEmptyWhenMissing(): void
    {
        $value = self::$reader->getSiteFieldAsString('missingsite');

        $this->assertSame('', $value);
    }

    public function testGetSiteFieldAsStringThrowsWhenRequiredAndMissing(): void
    {
        $this->expectException(KirbyRetrievalException::class);
        self::$reader->getSiteFieldAsString('missingsite', true);
    }

    public function testGetSiteFieldAsBoolReturnsTrue(): void
    {
        $this->assertTrue(self::$reader->getSiteFieldAsBool('siteboolfield'));
    }

    public function testGetSiteFieldAsBoolReturnsFalseByDefaultWhenMissing(): void
    {
        $this->assertFalse(self::$reader->getSiteFieldAsBool('missingsite'));
    }

    public function testGetSiteFieldAsArrayReturnsSplitValues(): void
    {
        $values = self::$reader->getSiteFieldAsArray('sitearrayfield');

        $this->assertSame(['one', 'two', 'three'], $values);
    }

    public function testGetSiteFieldAsArrayReturnsEmptyArrayWhenMissing(): void
    {
        $this->assertSame([], self::$reader->getSiteFieldAsArray('missingsite'));
    }

    public function testIsSiteFieldNotEmptyReturnsTrueWhenPopulated(): void
    {
        $this->assertTrue(self::$reader->isSiteFieldNotEmpty('sitestringfield'));
    }

    public function testIsSiteFieldNotEmptyReturnsFalseWhenMissing(): void
    {
        $this->assertFalse(self::$reader->isSiteFieldNotEmpty('missingsite'));
    }

    // =========================================================================
    // STRUCTURE FIELDS
    // =========================================================================

    private function makeStructure(array $rows): StructureObject
    {
        $yaml = Yaml::encode($rows);
        $page = $this->makePage(['items' => $yaml]);
        $structure = self::$reader->getPageFieldAsStructure($page, 'items');
        return $structure->first();
    }

    public function testGetStructureFieldThrowsWhenMissing(): void
    {
        $item = $this->makeStructure([['name' => 'Alice']]);

        $this->expectException(KirbyRetrievalException::class);
        self::$reader->getStructureField($item, 'missing');
    }

    public function testHasStructureFieldReturnsTrueWhenPresent(): void
    {
        $item = $this->makeStructure([['name' => 'Alice']]);

        $this->assertTrue(self::$reader->hasStructureField($item, 'name'));
    }

    public function testHasStructureFieldReturnsFalseWhenAbsent(): void
    {
        $item = $this->makeStructure([['name' => 'Alice']]);

        $this->assertFalse(self::$reader->hasStructureField($item, 'missing'));
    }

    public function testGetStructureFieldAsStringReturnsValue(): void
    {
        $item = $this->makeStructure([['name' => 'Alice']]);

        $this->assertSame('Alice', self::$reader->getStructureFieldAsString($item, 'name'));
    }

    public function testGetStructureFieldAsStringReturnsEmptyWhenMissing(): void
    {
        $item = $this->makeStructure([['name' => 'Alice']]);

        $this->assertSame('', self::$reader->getStructureFieldAsString($item, 'missing'));
    }

    public function testGetStructureFieldAsIntReturnsValue(): void
    {
        $item = $this->makeStructure([['age' => '30']]);

        $this->assertSame(30, self::$reader->getStructureFieldAsInt($item, 'age'));
    }

    public function testGetStructureFieldAsIntReturnsDefaultWhenMissing(): void
    {
        $item = $this->makeStructure([['name' => 'Alice']]);

        $this->assertSame(0, self::$reader->getStructureFieldAsInt($item, 'missing'));
    }

    public function testGetStructureFieldAsFloatReturnsValue(): void
    {
        $item = $this->makeStructure([['score' => '7.5']]);

        $this->assertEqualsWithDelta(7.5, self::$reader->getStructureFieldAsFloat($item, 'score'), 0.001);
    }

    public function testGetStructureFieldAsBoolReturnsTrueValue(): void
    {
        $item = $this->makeStructure([['active' => 'true']]);

        $this->assertTrue(self::$reader->getStructureFieldAsBool($item, 'active'));
    }

    public function testGetStructureFieldAsBoolReturnsDefaultWhenMissing(): void
    {
        $item = $this->makeStructure([['name' => 'Alice']]);

        $this->assertFalse(self::$reader->getStructureFieldAsBool($item, 'missing'));
        $this->assertTrue(self::$reader->getStructureFieldAsBool($item, 'missing', false, true));
    }

    public function testGetStructureAsArrayReturnsNamesFromStructure(): void
    {
        $yaml = Yaml::encode([['name' => 'Alpha'], ['name' => 'Beta']]);
        $page = $this->makePage(['items' => $yaml]);
        $structure = self::$reader->getPageFieldAsStructure($page, 'items');
        $result = self::$reader->getStructureAsArray($structure);

        $this->assertSame(['Alpha', 'Beta'], $result);
    }

    public function testGetStructureFieldThrowsWhenRequiredAndMissing(): void
    {
        $item = $this->makeStructure([['name' => 'Alice']]);

        $this->expectException(KirbyRetrievalException::class);
        self::$reader->getStructureFieldAsString($item, 'missing', true);
    }

    // =========================================================================
    // BLOCK FIELDS
    // =========================================================================

    private function makeTextBlock(string $text = 'Hello'): Block
    {
        $blocks = [['type' => 'text', 'content' => ['text' => $text, 'extra' => 'data']]];
        $page = $this->makePage(['content' => Json::encode($blocks)]);
        return self::$reader->getPageFieldAsBlocks($page, 'content')->first();
    }

    public function testGetBlockFieldThrowsWhenMissing(): void
    {
        $block = $this->makeTextBlock();

        $this->expectException(KirbyRetrievalException::class);
        self::$reader->getBlockField($block, 'missing');
    }

    public function testIsBlockFieldNotEmptyReturnsTrueWhenPresent(): void
    {
        $block = $this->makeTextBlock('Hello');

        $this->assertTrue(self::$reader->isBlockFieldNotEmpty($block, 'text'));
    }

    public function testIsBlockFieldNotEmptyReturnsFalseWhenMissing(): void
    {
        $block = $this->makeTextBlock();

        $this->assertFalse(self::$reader->isBlockFieldNotEmpty($block, 'missing'));
    }

    public function testGetBlockFieldAsStringReturnsValue(): void
    {
        $block = $this->makeTextBlock('Block content');

        $this->assertSame('Block content', self::$reader->getBlockFieldAsString($block, 'text'));
    }

    public function testGetBlockFieldAsStringReturnsEmptyWhenMissing(): void
    {
        $block = $this->makeTextBlock();

        $this->assertSame('', self::$reader->getBlockFieldAsString($block, 'missing'));
    }

    public function testGetBlockFieldAsStringThrowsWhenRequiredAndMissing(): void
    {
        $block = $this->makeTextBlock();

        $this->expectException(KirbyRetrievalException::class);
        self::$reader->getBlockFieldAsString($block, 'missing', true);
    }

    public function testGetBlockFieldAsYamlReturnsDecodedArray(): void
    {
        $data = [['item' => 'one'], ['item' => 'two']];
        $blocks = [['type' => 'text', 'content' => ['text' => 'hi', 'extra' => Yaml::encode($data)]]];
        $page = $this->makePage(['content' => Json::encode($blocks)]);
        $block = self::$reader->getPageFieldAsBlocks($page, 'content')->first();

        $result = self::$reader->getBlockFieldAsYaml($block, 'extra');

        $this->assertCount(2, $result);
        $this->assertSame('one', $result[0]['item']);
    }

    public function testGetBlockFieldAsYamlReturnsDefaultWhenMissing(): void
    {
        $block = $this->makeTextBlock();
        $default = ['fallback'];

        $this->assertSame($default, self::$reader->getBlockFieldAsYaml($block, 'missing', false, $default));
    }

    public function testGetBlockFieldAsFileReturnsNullWhenNoFile(): void
    {
        $blocks = [['type' => 'text', 'content' => ['text' => 'hi', 'image' => 'nonexistent.jpg']]];
        $page = $this->makePage(['content' => Json::encode($blocks)]);
        $block = self::$reader->getPageFieldAsBlocks($page, 'content')->first();

        $this->assertNull(self::$reader->getBlockFieldAsFile($block, 'image'));
    }

    // =========================================================================
    // getHTMLfromBlock
    // =========================================================================

    public function testGetHTMLfromBlockRendersTextBlockAsHtml(): void
    {
        $block = $this->makeTextBlock('<p>Hello world</p>');
        $html = self::$reader->getHTMLfromBlock($block);

        $this->assertIsString($html);
        $this->assertStringContainsString('Hello world', $html);
    }

    public function testGetHTMLfromBlockRendersNonTextBlockAsHtml(): void
    {
        $blocks = [['type' => 'heading', 'content' => ['text' => 'My Heading', 'level' => 'h2']]];
        $page = $this->makePage(['content' => Json::encode($blocks)]);
        $block = self::$reader->getPageFieldAsBlocks($page, 'content')->first();
        $html = self::$reader->getHTMLfromBlock($block);

        $this->assertIsString($html);
        $this->assertStringContainsString('My Heading', $html);
    }

    // =========================================================================
    // USER FIELDS
    // =========================================================================

    public function testGetUserFieldAsStringReturnsDefault(): void
    {
        $user = \Kirby\Cms\User::factory([
            'email' => 'test@example.com',
            'role'  => 'nobody',
        ]);

        $result = self::$reader->getUserFieldAsString($user, 'nonexistent', 'default-value');

        $this->assertSame('default-value', $result);
    }

    public function testIsUserFieldNotEmptyReturnsFalseForMissingField(): void
    {
        $user = \Kirby\Cms\User::factory([
            'email' => 'test@example.com',
            'role'  => 'nobody',
        ]);

        $this->assertFalse(self::$reader->isUserFieldNotEmpty($user, 'nonexistent'));
    }

    public function testGetUserFieldAsBoolReturnsFalseByDefault(): void
    {
        $user = \Kirby\Cms\User::factory([
            'email' => 'test@example.com',
            'role'  => 'nobody',
        ]);

        $this->assertFalse(self::$reader->getUserFieldAsBool($user, 'nonexistent'));
    }

    public function testGetUserFieldAsStructureReturnsRows(): void
    {
        $user = \Kirby\Cms\User::factory([
            'email'   => 'test@example.com',
            'role'    => 'nobody',
            'content' => [
                'certificates' => Yaml::encode([
                    ['context' => 'course-a', 'issued' => '2026-07-28'],
                    ['context' => 'course-b', 'issued' => '2026-07-29'],
                ]),
            ],
        ]);

        $structure = self::$reader->getUserFieldAsStructure($user, 'certificates');

        $this->assertCount(2, $structure);
        $this->assertSame('course-a', $structure->first()?->context()->value());
    }

    public function testGetUserFieldAsStructureReturnsEmptyStructureForMissingField(): void
    {
        $user = \Kirby\Cms\User::factory([
            'email' => 'test@example.com',
            'role'  => 'nobody',
        ]);

        $this->assertCount(0, self::$reader->getUserFieldAsStructure($user, 'nonexistent'));
    }

    /**
     * A malformed value must not take down the page it is read on.
     *
     * Kirby's YAML parsing is lenient and coerces junk into a row rather than
     * failing, so the guarantee here is that reading returns a usable Structure —
     * not that the row count is zero.
     */
    public function testGetUserFieldAsStructureSurvivesAMalformedValue(): void
    {
        $user = \Kirby\Cms\User::factory([
            'email'   => 'test@example.com',
            'role'    => 'nobody',
            'content' => ['certificates' => "\t- not: [valid\n  yaml at all"],
        ]);

        $this->assertInstanceOf(
            \Kirby\Cms\Structure::class,
            self::$reader->getUserFieldAsStructure($user, 'certificates')
        );
    }

    // =========================================================================
    // ENTRIES FIELDS
    // =========================================================================

    public function testGetEntriesFieldAsStringArrayThrowsWhenFieldMissing(): void
    {
        $page = $this->makePage();

        $this->expectException(KirbyRetrievalException::class);
        self::$reader->getEntriesFieldAsStringArray($page, 'missing');
    }

    // =========================================================================
    // FILE MODIFIED
    // =========================================================================

    public function testGetFileModifiedAsDateTimeReturnsDateTime(): void
    {
        // Create a temporary file we can wrap in a Kirby File
        $tmpFile = self::$tmpDir . '/test.txt';
        file_put_contents($tmpFile, 'test');

        $kirbyFile = \Kirby\Cms\File::factory([
            'filename' => 'test.txt',
            'parent'   => $this->makePage(['title' => 'Parent']),
        ]);

        // Only test the method exists and returns DateTime;
        // the actual timestamp depends on the file system.
        // We use getFileModifiedAsDateTime with a mock timestamp via a stub.
        // Since File::factory doesn't write to disk, modified() returns 0.
        $dt = self::$reader->getFileModifiedAsDateTime($kirbyFile);

        $this->assertInstanceOf(DateTime::class, $dt);
    }

    // =========================================================================
    // USERS — getUsers
    // =========================================================================

    public function testGetUsersReturnsUserListWithNameEmailAndJobTitle(): void
    {
        $page = $this->makePage(['postedBy' => "- jane@bsbi.org\n- bob@bsbi.org"]);

        $list = self::$reader->getUsers($page, 'postedBy');

        $this->assertSame(2, $list->count());

        $jane = $list->getListItems()[0];
        $this->assertSame('Jane Doe', $jane->getUserName());
        $this->assertSame('jane@bsbi.org', $jane->getEmail());
        $this->assertSame('Records Officer', $jane->getJobTitle());

        $bob = $list->getListItems()[1];
        $this->assertSame('Bob Lee', $bob->getUserName());
        $this->assertFalse($bob->hasJobTitle());
        $this->assertSame('', $bob->getJobTitle());
    }

    public function testGetUsersReturnsEmptyListWhenFieldEmpty(): void
    {
        $page = $this->makePage([]);

        $list = self::$reader->getUsers($page, 'postedBy');

        $this->assertSame(0, $list->count());
        $this->assertFalse($list->hasListItems());
    }

    public function testGetUsersInvokesEnricherForEachUserInOrder(): void
    {
        $page = $this->makePage(['postedBy' => "- jane@bsbi.org\n- bob@bsbi.org"]);

        $seenEmails = [];
        $list = self::$reader->getUsers(
            $page,
            'postedBy',
            function ($userModel, $kirbyUser) use (&$seenEmails): void {
                $seenEmails[] = $kirbyUser->email();
                $userModel->setProfileUrl('https://example.test/' . $kirbyUser->username());
            }
        );

        // Enricher runs once per user, in stored order, with the underlying Kirby user.
        $this->assertSame(['jane@bsbi.org', 'bob@bsbi.org'], $seenEmails);

        $jane = $list->getListItems()[0];
        $this->assertTrue($jane->hasProfileUrl());
        $this->assertSame('https://example.test/Jane Doe', $jane->getProfileUrl());
    }

    public function testGetUsersWithoutEnricherLeavesProfileUrlEmpty(): void
    {
        $page = $this->makePage(['postedBy' => '- jane@bsbi.org']);

        $list = self::$reader->getUsers($page, 'postedBy');

        $this->assertFalse($list->getListItems()[0]->hasProfileUrl());
    }

    // =========================================================================
    // CONTRIBUTORS — getContributors / getContributorNames
    // =========================================================================

    /**
     * Builds a master contributors Structure from the given rows.
     *
     * @param array<int, array<string, string>> $rows
     */
    private function makeContributors(array $rows): Structure
    {
        $page = $this->makePage(['contributors' => Yaml::encode($rows)]);

        return self::$reader->getPageFieldAsStructure($page, 'contributors');
    }

    public function testGetContributorsBuildsUserModelsFromMasterListInSelectionOrder(): void
    {
        $contributors = $this->makeContributors([
            ['name' => 'Jane Smith', 'jobRole' => 'Volunteer', 'email' => 'jane.smith@example.org'],
            ['name' => 'Bob Jones', 'jobRole' => 'Recorder', 'email' => 'bob.jones@example.org'],
        ]);
        // Selection order (Bob first) should be preserved, not master-list order.
        $page = $this->makePage(['postedByContributors' => 'Bob Jones, Jane Smith']);

        $list = self::$reader->getContributors($page, 'postedByContributors', $contributors);

        $this->assertSame(2, $list->count());

        $bob = $list->getListItems()[0];
        $this->assertSame('Bob Jones', $bob->getUserName());
        $this->assertSame('bob.jones@example.org', $bob->getEmail());
        $this->assertSame('Recorder', $bob->getJobTitle());
        // Contributors are not Kirby users.
        $this->assertFalse($bob->isLoggedIn());

        $jane = $list->getListItems()[1];
        $this->assertSame('Jane Smith', $jane->getUserName());
        $this->assertSame('jane.smith@example.org', $jane->getEmail());
        $this->assertSame('Volunteer', $jane->getJobTitle());
    }

    public function testGetContributorsReturnsEmptyListWhenNoneSelected(): void
    {
        $contributors = $this->makeContributors([
            ['name' => 'Jane Smith', 'jobRole' => 'Volunteer', 'email' => 'jane.smith@example.org'],
        ]);
        $page = $this->makePage([]);

        $list = self::$reader->getContributors($page, 'postedByContributors', $contributors);

        $this->assertSame(0, $list->count());
        $this->assertFalse($list->hasListItems());
    }

    public function testGetContributorsLeavesEmailAndJobTitleEmptyWhenMasterEntryLacksThem(): void
    {
        $contributors = $this->makeContributors([
            ['name' => 'Jane Smith'],
        ]);
        $page = $this->makePage(['postedByContributors' => 'Jane Smith']);

        $list = self::$reader->getContributors($page, 'postedByContributors', $contributors);

        $jane = $list->getListItems()[0];
        $this->assertSame('Jane Smith', $jane->getUserName());
        $this->assertFalse($jane->hasEmail());
        $this->assertSame('', $jane->getEmail());
        $this->assertFalse($jane->hasJobTitle());
        $this->assertSame('', $jane->getJobTitle());
    }

    public function testGetContributorsForNameNotInMasterListReturnsNameOnly(): void
    {
        $contributors = $this->makeContributors([
            ['name' => 'Jane Smith', 'jobRole' => 'Volunteer', 'email' => 'jane.smith@example.org'],
        ]);
        // "Ghost Author" was removed from the master list but is still selected.
        $page = $this->makePage(['postedByContributors' => 'Ghost Author']);

        $list = self::$reader->getContributors($page, 'postedByContributors', $contributors);

        $this->assertSame(1, $list->count());
        $ghost = $list->getListItems()[0];
        $this->assertSame('Ghost Author', $ghost->getUserName());
        $this->assertFalse($ghost->hasEmail());
        $this->assertFalse($ghost->hasJobTitle());
    }

    public function testGetContributorsWithEmptyMasterListReturnsNameOnlyPosters(): void
    {
        // Mirrors the fallback in getPostedByUsers when no contributors structure
        // exists yet: selections still resolve to name-only posters.
        $contributors = new Structure();
        $page = $this->makePage(['postedByContributors' => 'Jane Smith, Bob Jones']);

        $list = self::$reader->getContributors($page, 'postedByContributors', $contributors);

        $this->assertSame(2, $list->count());
        $this->assertSame('Jane Smith', $list->getListItems()[0]->getUserName());
        $this->assertFalse($list->getListItems()[0]->hasEmail());
        $this->assertSame('Bob Jones', $list->getListItems()[1]->getUserName());
    }

    public function testGetContributorNamesReturnsSelectedNamesJoined(): void
    {
        $page = $this->makePage(['postedByContributors' => 'Bob Jones, Jane Smith']);

        $this->assertSame(
            'Bob Jones, Jane Smith',
            self::$reader->getContributorNames($page, 'postedByContributors')
        );
    }

    public function testGetContributorNamesReturnsUnknownWhenEmpty(): void
    {
        $page = $this->makePage([]);

        $this->assertSame(
            'Unknown',
            self::$reader->getContributorNames($page, 'postedByContributors')
        );
    }
}
