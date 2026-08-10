<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\SearchIndexHelper;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
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

    // --- FTS5 query escaping ---

    /**
     * Real queries taken from the live `content-index` log, where each one threw an
     * FTS5 error. Scanner and dork traffic supplies most of them; ordinary visitors
     * supply the rest (a full stop, an apostrophe, a question mark).
     *
     * Every one has to be answerable — with results or without — rather than throwing,
     * because the caller's failure path is far more expensive than the search itself.
     *
     * @return array<string, array{string}>
     */
    public static function hostileQueryProvider(): array
    {
        return [
            'column filter (filetype:)' => ['filetype:pdf'],
            'column filter (inurl:)'    => ['inurl:admin'],
            'column filter (word:)'     => ['word:orchid'],
            'column filter (today:)'    => ['today:events'],
            'bare colon'                => [':'],
            'apostrophe'                => ["shepherd's purse"],
            'sql probe'                 => ["' OR 1=1"],
            'unterminated quote'        => ['"unclosed phrase'],
            'trailing full stop'        => ['orchid.'],
            'domain name'               => ['bsbi.org'],
            'question mark'             => ['what is a vice county?'],
            'email address'             => ['someone@example.com'],
            'dollar sign'               => ['$100 grant'],
            'equals sign'               => ['taxon=species'],
            'semicolon'                 => ['plants; drop table'],
            'fts special query'         => ['143#*'],
            'bare asterisks'            => ['**'],
            'punctuation only'          => ['...'],
            'fts NEAR operator'         => ['NEAR(orchid bee)'],
            'fts boolean operators'     => ['orchid AND NOT bee OR fly'],
            'caret column filter'       => ['^title'],
            'braces'                    => ['{title} : orchid'],
            'mixed rubbish'             => ['<>?@~#|\\`'],
        ];
    }

    /**
     * @throws \Exception
     */
    #[DataProvider('hostileQueryProvider')]
    public function testSearchAllIdsAnswersHostileQueriesInsteadOfThrowing(string $query): void
    {
        $helper = $this->makeHelper();
        $this->insertPage($helper->getDatabase(), 'plants/orchid', 'Orchid');

        $this->assertIsArray($helper->searchAllIds($query, true));
    }

    /**
     * @throws \Exception
     */
    #[DataProvider('hostileQueryProvider')]
    public function testSearchIdsPageAnswersHostileQueriesInsteadOfThrowing(string $query): void
    {
        $helper = $this->makeHelper();
        $this->insertPage($helper->getDatabase(), 'plants/orchid', 'Orchid');

        $result = $helper->searchIdsPage($query, true, null, 10, 0);

        $this->assertIsArray($result['ids']);
        $this->assertIsInt($result['total']);
    }

    /**
     * Escaping must not cost ordinary searches their results.
     *
     * @throws \Exception
     */
    public function testPlainWordStillMatches(): void
    {
        $helper = $this->makeHelper();
        $this->insertPage($helper->getDatabase(), 'plants/orchid', 'Orchid');

        $this->assertSame(['plants/orchid'], $helper->searchAllIds('orchid', true));
    }

    /**
     * @throws \Exception
     */
    public function testPrefixMatchingStillWorks(): void
    {
        $helper = $this->makeHelper();
        $this->insertPage($helper->getDatabase(), 'plants/orchid', 'Orchid');

        $this->assertSame(['plants/orchid'], $helper->searchAllIds('orch', true));
    }

    /**
     * @throws \Exception
     */
    public function testQuotedPhraseStillWorks(): void
    {
        $helper = $this->makeHelper();
        $this->insertPage($helper->getDatabase(), 'plants/rpr', 'Rare Plant Register');
        $this->insertPage($helper->getDatabase(), 'plants/other', 'Plant Rare Nonsense Register');

        $this->assertSame(['plants/rpr'], $helper->searchAllIds('"rare plant"', true));
    }

    /**
     * A word carrying punctuation must still find the page, rather than erroring or
     * silently matching nothing — "shepherd's purse" is an ordinary thing to search for.
     *
     * @throws \Exception
     */
    public function testWordWithAnApostropheStillMatches(): void
    {
        $helper = $this->makeHelper();
        $this->insertPage($helper->getDatabase(), 'plants/purse', "Shepherd's Purse");

        $this->assertSame(['plants/purse'], $helper->searchAllIds("shepherd's", true));
    }

    /**
     * @throws \Exception
     */
    public function testTrailingFullStopStillMatches(): void
    {
        $helper = $this->makeHelper();
        $this->insertPage($helper->getDatabase(), 'plants/orchid', 'Orchid');

        $this->assertSame(['plants/orchid'], $helper->searchAllIds('orchid.', true));
    }

    /**
     * A query that is nothing but punctuation has no searchable token in it, so it
     * finds nothing — but it must do so without reaching FTS5 at all.
     *
     * @throws \Exception
     */
    public function testPunctuationOnlyQueryFindsNothing(): void
    {
        $helper = $this->makeHelper();
        $this->insertPage($helper->getDatabase(), 'plants/orchid', 'Orchid');

        $this->assertSame([], $helper->searchAllIds('...', true));
    }

    /**
     * Punctuation must not smuggle a stop word past the filter: "the." is as much a
     * stop word as "the", so stripping happens before the filters, not after.
     *
     * @throws \Exception
     */
    public function testAStopWordCarryingPunctuationIsStillFiltered(): void
    {
        $helper = $this->makeHelper();
        $this->insertPage($helper->getDatabase(), 'plants/orchid', 'Orchid');

        // "the." contributes nothing, so this is a search for "orchid" alone. Were it
        // kept, the page would have to contain "the" as well and would not match.
        $this->assertSame(['plants/orchid'], $helper->searchAllIds('the. orchid', true));
    }

    /**
     * @throws \Exception
     */
    public function testAccentedCharactersAreKept(): void
    {
        $helper = $this->makeHelper();
        $this->insertPage($helper->getDatabase(), 'plants/aren', 'Ærenpris');

        $this->assertSame(['plants/aren'], $helper->searchAllIds('Ærenpris', true));
    }

    // --- searchIdsPage: bounded result sets ---

    /**
     * @param SearchIndexHelperTestDouble $helper the helper to seed
     * @param int $count how many matching pages to insert
     */
    private function seedMatchingPages(SearchIndexHelperTestDouble $helper, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $this->insertPage($helper->getDatabase(), 'plants/orchid-' . $i, 'Orchid ' . $i);
        }
    }

    /**
     * The whole point: a broad query must return only the page asked for, however many
     * pages match. Returning every id is what exhausted memory on live, because the
     * caller turned each one into a Kirby page object before paginating.
     *
     * @throws \Exception
     */
    public function testSearchIdsPageReturnsOnlyTheRequestedSlice(): void
    {
        $helper = $this->makeHelper();
        $this->seedMatchingPages($helper, 50);

        $result = $helper->searchIdsPage('orchid', true, null, 10, 0);

        $this->assertCount(10, $result['ids']);
    }

    /**
     * @throws \Exception
     */
    public function testSearchIdsPageReportsTheFullTotalNotTheSliceSize(): void
    {
        $helper = $this->makeHelper();
        $this->seedMatchingPages($helper, 50);

        $this->assertSame(50, $helper->searchIdsPage('orchid', true, null, 10, 0)['total']);
    }

    /**
     * @throws \Exception
     */
    public function testSearchIdsPageOffsetMovesThroughTheResults(): void
    {
        $helper = $this->makeHelper();
        $this->seedMatchingPages($helper, 50);

        $first  = $helper->searchIdsPage('orchid', true, null, 10, 0)['ids'];
        $second = $helper->searchIdsPage('orchid', true, null, 10, 10)['ids'];

        $this->assertCount(10, $second);
        $this->assertSame([], array_intersect($first, $second));
    }

    /**
     * @throws \Exception
     */
    public function testSearchIdsPagePastTheEndReturnsNoIdsButKeepsTheTotal(): void
    {
        $helper = $this->makeHelper();
        $this->seedMatchingPages($helper, 5);

        $result = $helper->searchIdsPage('orchid', true, null, 10, 100);

        $this->assertSame([], $result['ids']);
        $this->assertSame(5, $result['total']);
    }

    /**
     * @throws \Exception
     */
    public function testSearchIdsPageOrdersTheSameWayAsSearchAllIds(): void
    {
        $helper = $this->makeHelper();
        $this->insertPage($helper->getDatabase(), 'plants/a', 'Orchid', '', 'orchid orchid orchid');
        $this->insertPage($helper->getDatabase(), 'plants/b', 'Bee Orchid');
        $this->insertPage($helper->getDatabase(), 'plants/c', 'Orchid Meadow');

        $all  = $helper->searchAllIds('orchid', true);
        $page = $helper->searchIdsPage('orchid', true, null, 3, 0)['ids'];

        $this->assertSame(array_slice($all, 0, 3), $page);
    }

    /**
     * @throws \Exception
     */
    public function testSearchIdsPageAppliesTheTemplateFilter(): void
    {
        $helper = $this->makeHelper();
        $this->insertPage($helper->getDatabase(), 'plants/orchid', 'Orchid');

        $this->assertSame(0, $helper->searchIdsPage('orchid', true, 'species', 10, 0)['total']);
        $this->assertSame(1, $helper->searchIdsPage('orchid', true, 'page', 10, 0)['total']);
    }

    /**
     * A query with no searchable token must not reach FTS5 or count anything.
     *
     * @throws \Exception
     */
    public function testSearchIdsPageWithAnEmptyQueryReturnsNothing(): void
    {
        $helper = $this->makeHelper();
        $this->seedMatchingPages($helper, 5);

        $result = $helper->searchIdsPage('...', true, null, 10, 0);

        $this->assertSame([], $result['ids']);
        $this->assertSame(0, $result['total']);
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
