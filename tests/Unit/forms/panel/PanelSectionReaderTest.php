<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\forms\panel;

use BSBI\WebBase\forms\FormFieldSpec;
use BSBI\WebBase\forms\panel\FormProblems;
use BSBI\WebBase\forms\panel\PanelField;
use BSBI\WebBase\forms\panel\PanelSectionReader;
use BSBI\WebBase\Testing\KirbyTestEnvironment;
use Kirby\Data\Json;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PanelSectionReader: a library section page gives its fields, and a
 * variation gives its base's fields followed by its own.
 */
final class PanelSectionReaderTest extends TestCase
{
    use PanelFormFixtures;

    public static function setUpBeforeClass(): void
    {
        KirbyTestEnvironment::boot('kirby-base-panel-section-reader-' . uniqid());
    }

    public function testSectionGivesItsFieldsInOrder(): void
    {
        $section = $this->sectionPage([
            $this->blockData('form-textbox', ['label' => 'First name', 'name' => 'first_name']),
            $this->blockData('form-textbox', ['label' => 'Email', 'name' => 'email']),
        ]);

        $problems = new FormProblems();
        $fields = (new PanelSectionReader($this->resolver([])))->read($section, $problems);

        $this->assertSame(['first_name', 'email'], $this->keys($fields));
        $this->assertTrue($problems->isEmpty());
    }

    public function testVariationGivesBaseFieldsThenItsOwn(): void
    {
        $base = $this->sectionPage([
            $this->blockData('form-textbox', ['label' => 'Email', 'name' => 'email']),
        ], slug: 'contact');
        $variation = $this->sectionPage([
            $this->blockData('form-textbox', ['label' => 'Phone', 'name' => 'phone']),
        ], extends: 'page://contact');

        $fields = (new PanelSectionReader($this->resolver(['page://contact' => $base])))
            ->read($variation, new FormProblems());

        $this->assertSame(['email', 'phone'], $this->keys($fields));
    }

    public function testEditingTheBaseChangesTheVariation(): void
    {
        $variation = $this->sectionPage([
            $this->blockData('form-textbox', ['label' => 'Phone', 'name' => 'phone']),
        ], extends: 'page://contact');

        $edited = $this->sectionPage([
            $this->blockData('form-textbox', ['label' => 'Email', 'name' => 'email']),
            $this->blockData('form-textbox', ['label' => 'Postcode', 'name' => 'postcode']),
        ], slug: 'contact');

        $fields = (new PanelSectionReader($this->resolver(['page://contact' => $edited])))
            ->read($variation, new FormProblems());

        $this->assertSame(['email', 'postcode', 'phone'], $this->keys($fields));
    }

    public function testVariationsChain(): void
    {
        $a = $this->sectionPage([$this->blockData('form-textbox', ['label' => 'A', 'name' => 'a'])], slug: 'a');
        $b = $this->sectionPage([$this->blockData('form-textbox', ['label' => 'B', 'name' => 'b'])], extends: 'page://a', slug: 'b');
        $c = $this->sectionPage([$this->blockData('form-textbox', ['label' => 'C', 'name' => 'c'])], extends: 'page://b');

        $fields = (new PanelSectionReader($this->resolver(['page://a' => $a, 'page://b' => $b])))
            ->read($c, new FormProblems());

        $this->assertSame(['a', 'b', 'c'], $this->keys($fields));
    }

    public function testCycleIsReportedAndBroken(): void
    {
        $a = $this->sectionPage([$this->blockData('form-textbox', ['label' => 'A', 'name' => 'a'])], extends: 'page://b', slug: 'a');
        $b = $this->sectionPage([$this->blockData('form-textbox', ['label' => 'B', 'name' => 'b'])], extends: 'page://a', slug: 'b');

        $problems = new FormProblems();
        $fields = (new PanelSectionReader($this->resolver(['page://a' => $a, 'page://b' => $b])))
            ->read($a, $problems);

        $this->assertSame(['b', 'a'], $this->keys($fields));
        $this->assertStringContainsString('loop', $problems->all()[0]);
    }

    public function testChainIdsFollowEveryBase(): void
    {
        $a = $this->sectionPage([], slug: 'a');
        $b = $this->sectionPage([], extends: 'page://a', slug: 'b');
        $c = $this->sectionPage([], extends: 'page://b', slug: 'c');

        $ids = (new PanelSectionReader($this->resolver(['page://a' => $a, 'page://b' => $b])))->chainIds($c);

        $this->assertSame(['c', 'b', 'a'], $ids);
    }

    public function testChainIdsStopAtALoopOrAMissingBase(): void
    {
        $a = $this->sectionPage([], extends: 'page://b', slug: 'a');
        $b = $this->sectionPage([], extends: 'page://a', slug: 'b');
        $orphan = $this->sectionPage([], extends: 'page://gone', slug: 'orphan');
        $reader = new PanelSectionReader($this->resolver(['page://a' => $a, 'page://b' => $b]));

        $this->assertSame(['a', 'b'], $reader->chainIds($a));
        $this->assertSame(['orphan'], $reader->chainIds($orphan));
    }

    public function testMissingBaseIsReportedAndOwnFieldsStillRead(): void
    {
        $variation = $this->sectionPage([
            $this->blockData('form-textbox', ['label' => 'Phone', 'name' => 'phone']),
        ], extends: 'page://gone');

        $problems = new FormProblems();
        $fields = (new PanelSectionReader($this->resolver([])))->read($variation, $problems);

        $this->assertSame(['phone'], $this->keys($fields));
        $this->assertCount(1, $problems->all());
        $this->assertStringContainsString('cannot be found', $problems->all()[0]);
    }

    public function testChainDeeperThanTheLimitIsReported(): void
    {
        $pages = [];
        $previous = null;
        for ($i = 0; $i <= PanelSectionReader::MAX_DEPTH + 1; $i++) {
            $page = $this->sectionPage(
                [$this->blockData('form-textbox', ['label' => "F$i", 'name' => "f$i"])],
                extends: $previous,
                slug: "s$i"
            );
            $pages["page://s$i"] = $page;
            $previous = "page://s$i";
        }

        $problems = new FormProblems();
        $top = $pages["page://s" . (PanelSectionReader::MAX_DEPTH + 1)];
        (new PanelSectionReader($this->resolver($pages)))->read($top, $problems);

        $this->assertStringContainsString('deeper', $problems->all()[0]);
    }

    public function testChainExactlyAtTheLimitIsReadInFull(): void
    {
        $pages = [];
        $previous = null;
        for ($i = 0; $i <= PanelSectionReader::MAX_DEPTH; $i++) {
            $pages["page://s$i"] = $this->sectionPage(
                [$this->blockData('form-textbox', ['label' => "F$i", 'name' => "f$i"])],
                extends: $previous,
                slug: "s$i"
            );
            $previous = "page://s$i";
        }

        $problems = new FormProblems();
        $top = $pages['page://s' . PanelSectionReader::MAX_DEPTH];
        $fields = (new PanelSectionReader($this->resolver($pages)))->read($top, $problems);

        $this->assertCount(PanelSectionReader::MAX_DEPTH + 1, $fields);
        $this->assertTrue($problems->isEmpty());
    }

    public function testUnknownBlockTypesAreReported(): void
    {
        $section = $this->sectionPage([
            $this->blockData('text', ['text' => 'stray']),
            $this->blockData('form-textbox', ['label' => 'Email', 'name' => 'email']),
        ]);

        $problems = new FormProblems();
        $fields = (new PanelSectionReader($this->resolver([])))->read($section, $problems);

        $this->assertSame(['email'], $this->keys($fields));
        $this->assertStringContainsString('text', $problems->all()[0]);
    }

    public function testFieldsAreSpecs(): void
    {
        $section = $this->sectionPage([
            $this->blockData('form-textarea', ['label' => 'Notes', 'name' => 'notes']),
        ]);
        $fields = (new PanelSectionReader($this->resolver([])))->read($section, new FormProblems());

        $this->assertInstanceOf(FormFieldSpec::class, $fields[0]->spec);
    }

    public function testBlockWithoutAnIdGetsTheSameKeyOnEveryReadAndIsReported(): void
    {
        $raw = [['type' => 'form-textbox', 'isHidden' => false, 'content' => ['label' => 'Email']]];
        $builder = new \BSBI\WebBase\Testing\KirbyContentBuilder();
        $reader = new PanelSectionReader($this->resolver([]));

        $firstProblems = new FormProblems();
        $first = $reader->read($builder->page(['title' => 'Contact', 'formFields' => Json::encode($raw)], 'contact'), $firstProblems);
        $second = $reader->read($builder->page(['title' => 'Contact', 'formFields' => Json::encode($raw)], 'contact'), new FormProblems());

        $this->assertMatchesRegularExpression('/^f_[a-f0-9]{8}$/', $first[0]->key);
        $this->assertSame($first[0]->key, $second[0]->key);
        $this->assertStringContainsString('save', $firstProblems->all()[0]);
    }

    public function testBlocksWithoutIdsInDifferentSectionsGetDifferentKeys(): void
    {
        $raw = Json::encode([['type' => 'form-textbox', 'isHidden' => false, 'content' => ['label' => 'Q']]]);
        $builder = new \BSBI\WebBase\Testing\KirbyContentBuilder();
        $reader = new PanelSectionReader($this->resolver([]));

        $one = $reader->read($builder->page(['formFields' => $raw], 'one'), new FormProblems());
        $two = $reader->read($builder->page(['formFields' => $raw], 'two'), new FormProblems());

        $this->assertNotSame($one[0]->key, $two[0]->key);
    }

    public function testCorruptBlocksDataIsReportedNotThrown(): void
    {
        $section = (new \BSBI\WebBase\Testing\KirbyContentBuilder())->page(['title' => 'Broken', 'formFields' => '[1, 2]']);

        $problems = new FormProblems();
        $fields = (new PanelSectionReader($this->resolver([])))->read($section, $problems);

        $this->assertSame([], $fields);
        $this->assertStringContainsString('could not be read', $problems->all()[0]);
    }

    /**
     * @param PanelField[] $fields
     * @return string[]
     */
    private function keys(array $fields): array
    {
        return array_map(static fn(PanelField $f): string => $f->key, $fields);
    }
}
