<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\SearchIndexHelper;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SearchIndexHelper.
 *
 * normaliseKeySearchPhrases() is a public static method and can be tested
 * directly without a Kirby environment.
 *
 * The exact-phrase boost SQL is verified through an in-memory FTS5 database
 * using a test subclass that bypasses the Kirby-dependent constructor.
 */
final class SearchIndexHelperTest extends TestCase
{
    // --- normaliseKeySearchPhrases ---

    /**
     * Verify that a single phrase is returned unchanged (no trailing delimiter).
     */
    public function testNormaliseSinglePhrase(): void
    {
        $result = SearchIndexHelper::normaliseKeySearchPhrases('rare plant register');
        $this->assertSame('rare plant register', $result);
    }

    /**
     * Verify that multiple newline-separated phrases are joined with '|'.
     */
    public function testNormaliseMultiplePhrases(): void
    {
        $result = SearchIndexHelper::normaliseKeySearchPhrases("rare plant register\nRare Plant Registers");
        $this->assertSame('rare plant register|Rare Plant Registers', $result);
    }

    /**
     * Verify that Windows-style CRLF line endings are handled.
     */
    public function testNormaliseCrlfLineEndings(): void
    {
        $result = SearchIndexHelper::normaliseKeySearchPhrases("phrase one\r\nphrase two");
        $this->assertSame('phrase one|phrase two', $result);
    }

    /**
     * Verify that blank lines between phrases are ignored.
     */
    public function testNormaliseFiltersBlankLines(): void
    {
        $result = SearchIndexHelper::normaliseKeySearchPhrases("phrase one\n\nphrase two\n");
        $this->assertSame('phrase one|phrase two', $result);
    }

    /**
     * Verify that leading and trailing whitespace is trimmed from each phrase.
     */
    public function testNormaliseTrimsWhitespace(): void
    {
        $result = SearchIndexHelper::normaliseKeySearchPhrases("  rare plant register  \n  another phrase  ");
        $this->assertSame('rare plant register|another phrase', $result);
    }

    /**
     * Verify that an empty string input returns an empty string.
     */
    public function testNormaliseEmptyInputReturnsEmptyString(): void
    {
        $this->assertSame('', SearchIndexHelper::normaliseKeySearchPhrases(''));
    }

    /**
     * Verify that a whitespace-only input returns an empty string.
     */
    public function testNormaliseWhitespaceOnlyInputReturnsEmptyString(): void
    {
        $this->assertSame('', SearchIndexHelper::normaliseKeySearchPhrases("   \n  \n  "));
    }

    // --- key_search_phrases exact-phrase boost (in-memory FTS5) ---

    /**
     * Build an in-memory FTS5 database with the current search_index schema
     * and return a test-subclass instance wired to it.
     *
     * @throws \Exception
     */
    private function makeHelper(): SearchIndexHelperTestDouble
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('
            CREATE VIRTUAL TABLE search_index USING fts5(
                page_id,
                title,
                vernacular_name,
                description,
                keywords,
                main_content,
                additional_content,
                key_search_phrases,
                url,
                is_members_only,
                template,
                last_updated UNINDEXED,
                tokenize="porter unicode61"
            )
        ');
        $db->exec("CREATE TABLE search_meta (key TEXT PRIMARY KEY, value TEXT)");
        return new SearchIndexHelperTestDouble($db);
    }

    /**
     * Insert a minimal page row into the in-memory search_index.
     *
     * @param PDO    $db              The in-memory database
     * @param string $pageId          Unique page identifier
     * @param string $title           Page title
     * @param string $keyPhrases      Pipe-delimited key search phrases (already normalised)
     * @param string $additionalWords Extra words to pad out additional_content for BM25 score
     */
    private function insertPage(
        PDO $db,
        string $pageId,
        string $title,
        string $keyPhrases = '',
        string $additionalWords = ''
    ): void {
        $stmt = $db->prepare('
            INSERT INTO search_index
                (page_id, title, vernacular_name, description, keywords,
                 main_content, additional_content, key_search_phrases,
                 url, is_members_only, template, last_updated)
            VALUES
                (:page_id, :title, "", "", "",
                 "", :additional_content, :key_search_phrases,
                 "", "0", "page", "2025-01-01")
        ');
        $stmt->execute([
            'page_id'            => $pageId,
            'title'              => $title,
            'additional_content' => $additionalWords,
            'key_search_phrases' => $keyPhrases,
        ]);
    }

    /**
     * A page with a matching key_search_phrases entry should rank above a page
     * whose title contains the same words many times (better raw BM25 score).
     *
     * @throws \Exception
     */
    public function testKeyPhrasePageRanksAboveHighBm25Page(): void
    {
        $helper = $this->makeHelper();
        $db     = $helper->getDatabase();

        // Page A: title matches query words repeatedly — would normally win on BM25 alone
        $this->insertPage(
            $db,
            'publications/rare-plant-registers',
            'Rare Plant Registers',
            'rare plant register|Rare Plant Registers'
        );

        // Page B: many mentions of the words in various fields — high raw BM25
        $this->insertPage(
            $db,
            'publications/rare-plant-registers/somerset-2023',
            'Somerset Rare Plant Register 2023',
            '',
            'rare plant register rare plant register rare plant register'
        );

        $results = $helper->searchPublic('rare plant register');
        $this->assertNotEmpty($results['results'], 'Expected at least one result');

        $firstPageId = $results['results'][0]['page_id'];
        $this->assertSame(
            'publications/rare-plant-registers',
            $firstPageId,
            'Page with matching key_search_phrases should rank first'
        );
    }

    /**
     * When no key_search_phrases match, normal BM25 ranking applies unmodified.
     *
     * @throws \Exception
     */
    public function testNormalRankingPreservedWhenNoPhraseMatch(): void
    {
        $helper = $this->makeHelper();
        $db     = $helper->getDatabase();

        // Page A: title exactly matches the query — should rank first via exactMatchBoost
        $this->insertPage($db, 'pages/orchids', 'Orchids');
        // Page B: orchids mentioned only in additional content
        $this->insertPage($db, 'pages/flowers', 'Flowers', '', 'orchids');

        $results = $helper->searchPublic('orchids');
        $this->assertNotEmpty($results['results']);
        $this->assertSame('pages/orchids', $results['results'][0]['page_id']);
    }

    // --- removePage on a database predating page_lookup (issue #6) ---

    /**
     * Build a database with the schema as it was before page_lookup existed.
     *
     * Just the FTS5 table and search_meta — which is what an established install
     * actually has. A freshly created database has every table and so cannot
     * reproduce this at all, which is why it went unnoticed.
     *
     * @return PDO the legacy-schema database
     */
    private function legacyDatabase(): PDO
    {
        $database = new PDO('sqlite::memory:');
        $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $database->exec('CREATE VIRTUAL TABLE search_index USING fts5(page_id, title, url)');
        $database->exec('CREATE TABLE search_meta (key TEXT PRIMARY KEY, value TEXT)');
        $database->exec("INSERT INTO search_index (page_id, title, url) VALUES ('some/page', 'T', '/u')");

        return $database;
    }

    /**
     * @param PDO $database the database to inspect
     * @param string $table the table name
     * @return bool whether the table exists
     */
    private function tableExists(PDO $database, string $table): bool
    {
        $stmt = $database->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :name");
        $stmt->execute(['name' => $table]);

        return $stmt->fetch() !== false;
    }

    /**
     * Verify removePage() does not throw when page_lookup is absent.
     *
     * It used to. Every page deletion on an established install threw, was
     * swallowed by the page.delete:before hook, and wrote a line to
     * search-index.log — a million and a half bytes of it on one site.
     */
    public function testRemovePageDoesNotThrowWithoutPageLookup(): void
    {
        $helper = new SearchIndexHelperTestDouble($this->legacyDatabase());

        $this->assertTrue($helper->removePage('some/page'));
    }

    /**
     * Verify the FTS5 row is actually removed.
     */
    public function testRemovePageRemovesTheIndexedRow(): void
    {
        $database = $this->legacyDatabase();
        $helper = new SearchIndexHelperTestDouble($database);

        $helper->removePage('some/page');

        $stmt = $database->query("SELECT COUNT(*) FROM search_index WHERE page_id = 'some/page'");
        $this->assertSame(0, (int)$stmt->fetchColumn());
    }

    /**
     * Verify the cleanup after the throwing statement now runs.
     *
     * This is the part that made the bug worse than a missing table. The
     * exception landed *between* the page_lookup delete and removeAllPagesEntry(),
     * which is correctly guarded and simply never got to run — so all_pages
     * retained a row for every page ever deleted.
     */
    public function testRemovePageReachesTheAllPagesCleanup(): void
    {
        $database = $this->legacyDatabase();
        $helper = new SearchIndexHelperTestDouble($database);

        $helper->removePage('some/page');

        $this->assertTrue($this->tableExists($database, 'all_pages'));
    }

    /**
     * Verify the missing table is created rather than merely tolerated.
     */
    public function testRemovePageCreatesPageLookup(): void
    {
        $database = $this->legacyDatabase();
        $helper = new SearchIndexHelperTestDouble($database);

        $helper->removePage('some/page');

        $this->assertTrue($this->tableExists($database, 'page_lookup'));
    }

    /**
     * Verify the insert side is guarded too.
     */
    public function testAddPageLookupDoesNotThrowWithoutPageLookup(): void
    {
        $helper = new SearchIndexHelperTestDouble($this->legacyDatabase());

        $this->assertTrue($helper->addPageLookup('ddb-1', 'some/page'));
    }
}

/**
 * Test double for SearchIndexHelper that bypasses the Kirby-dependent constructor
 * and overrides every protected config getter to return class-defaults directly,
 * so no Kirby environment is required.
 */
final class SearchIndexHelperTestDouble extends SearchIndexHelper
{
    /** Deliberately skips parent::__construct() — it requires a live Kirby instance. */
    // @noinspection MissingParentConstructorInspection
    public function __construct(PDO $database)
    {
        $this->database = $database;
    }

    /**
     * Expose the internal database for seeding test data.
     */
    public function getDatabase(): PDO
    {
        return $this->database;
    }

    /**
     * Call search() with sensible test defaults.
     *
     * @param string $query The search query
     * @return array{results: array<array<string,string>>, total: int}
     */
    public function searchPublic(string $query): array
    {
        return $this->search($query, true);
    }

    // --- Override config getters to avoid calling option() (requires Kirby) ---

    /** @return array<string> */
    protected function getStopWords(): array
    {
        return ['a', 'an', 'the', 'and', 'or', 'in', 'on', 'at', 'to', 'for',
            'of', 'with', 'by', 'is', 'are', 'was', 'were', 'it', 'its'];
    }

    /** @return array<string, float> */
    protected function getFieldWeights(): array
    {
        return [
            'page_id'            => 0.0,
            'title'              => 10.0,
            'vernacular_name'    => 20.0,
            'description'        => 5.0,
            'keywords'           => 5.0,
            'main_content'       => 1.0,
            'additional_content' => 5.0,
            'key_search_phrases' => 10000.0,
            'url'                => 0.0,
            'is_members_only'    => 0.0,
            'template'           => 0.0,
        ];
    }

    protected function getExactMatchBoost(): int
    {
        return 100;
    }

    /** @return array<string> */
    protected function getExactMatchFields(): array
    {
        return ['vernacular_name', 'title'];
    }

    protected function getKeyPhraseBoost(): int
    {
        return 100000;
    }
}
