<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\PageTreeService;
use BSBI\WebBase\Testing\KirbyTestEnvironment;
use Kirby\Cms\Page;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PageTreeService.
 *
 * Kirby is booted once up front so the global handlers it registers stay out of the
 * per-test window; each test then fabricates its own standalone page tree.
 */
final class PageTreeServiceTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        KirbyTestEnvironment::boot('kirby-base-page-tree-tests');
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /**
     * Builds a standalone root page holding the given child definitions.
     *
     * @param array<int, array<string, mixed>> $children Kirby page definitions
     */
    private function makeTree(array $children): Page
    {
        return new Page([
            'slug'     => 'root',
            'content'  => ['title' => 'Root'],
            'children' => $children,
        ]);
    }

    /**
     * Builds a page definition.
     *
     * @param array<int, array<string, mixed>> $children
     * @return array<string, mixed>
     */
    private function definition(string $slug, string $template, array $children = []): array
    {
        return [
            'slug'     => $slug,
            'template' => $template,
            'content'  => ['title' => $slug],
            'children' => $children,
        ];
    }

    // ── Tests ─────────────────────────────────────────────────────────────

    public function testReturnsEveryDescendantWhenNoLeafTemplatesAreGiven(): void
    {
        $root = $this->makeTree([
            $this->definition('unit', 'unit', [
                $this->definition('sheet', 'question-sheet', [
                    $this->definition('submission', 'submission'),
                ]),
            ]),
        ]);

        $index = (new PageTreeService())->indexExcludingChildrenOf($root, []);

        $this->assertSame($root->index()->keys(), $index->keys());
        $this->assertSame(
            ['root/unit', 'root/unit/sheet', 'root/unit/sheet/submission'],
            $index->keys()
        );
    }

    public function testStopsAtALeafTemplateButKeepsTheLeafItself(): void
    {
        $root = $this->makeTree([
            $this->definition('unit', 'unit', [
                $this->definition('sheet', 'question-sheet', [
                    $this->definition('submission-a', 'submission'),
                    $this->definition('submission-b', 'submission'),
                ]),
            ]),
        ]);

        $index = (new PageTreeService())->indexExcludingChildrenOf($root, ['question-sheet']);

        $this->assertSame(['root/unit', 'root/unit/sheet'], $index->keys());
        $this->assertSame(4, $root->index()->count(), 'index() would also pull in the submissions');
    }

    public function testPrunesEveryListedLeafTemplate(): void
    {
        $root = $this->makeTree([
            $this->definition('sheet', 'question-sheet', [
                $this->definition('submission', 'submission'),
            ]),
            $this->definition('question', 'forum-question', [
                $this->definition('post', 'forum-post'),
            ]),
        ]);

        $index = (new PageTreeService())
            ->indexExcludingChildrenOf($root, ['question-sheet', 'forum-question']);

        $this->assertSame(['root/sheet', 'root/question'], $index->keys());
    }

    public function testKeepsKirbyDepthFirstOrdering(): void
    {
        $root = $this->makeTree([
            $this->definition('unit-1', 'unit', [
                $this->definition('sheet-1', 'question-sheet'),
                $this->definition('sheet-2', 'question-sheet'),
            ]),
            $this->definition('unit-2', 'unit', [
                $this->definition('sheet-3', 'question-sheet'),
            ]),
        ]);

        $index = (new PageTreeService())->indexExcludingChildrenOf($root, ['question-sheet']);

        // identical to index() when nothing sits below a leaf template
        $this->assertSame($root->index()->keys(), $index->keys());
        $this->assertSame(
            ['root/unit-1', 'root/unit-1/sheet-1', 'root/unit-1/sheet-2', 'root/unit-2', 'root/unit-2/sheet-3'],
            $index->keys()
        );
    }

    public function testPrunesAtEveryDepthNotJustTheTop(): void
    {
        $root = $this->makeTree([
            $this->definition('section', 'default', [
                $this->definition('unit', 'unit', [
                    $this->definition('sheet', 'question-sheet', [
                        $this->definition('submission', 'submission'),
                    ]),
                ]),
            ]),
        ]);

        $index = (new PageTreeService())->indexExcludingChildrenOf($root, ['question-sheet']);

        $this->assertSame(
            ['root/section', 'root/section/unit', 'root/section/unit/sheet'],
            $index->keys()
        );
    }

    public function testReturnsAnEmptyCollectionForAChildlessPage(): void
    {
        $root = $this->makeTree([]);

        $index = (new PageTreeService())->indexExcludingChildrenOf($root, ['question-sheet']);

        $this->assertCount(0, $index);
    }

    public function testMatchesTheIntendedTemplateSoAMissingTemplateFileStillPrunes(): void
    {
        // no template files exist in the test environment, so template() would resolve
        // every page to 'default'; the intended template is what identifies the page type
        $root = $this->makeTree([
            $this->definition('sheet', 'question-sheet', [
                $this->definition('submission', 'submission'),
            ]),
        ]);

        $this->assertSame('default', $root->children()->first()?->template()->name());

        $index = (new PageTreeService())->indexExcludingChildrenOf($root, ['question-sheet']);

        $this->assertSame(['root/sheet'], $index->keys());
    }

    public function testUnlistedLeafTemplatesAreStillWalkedThrough(): void
    {
        $root = $this->makeTree([
            $this->definition('unit', 'unit', [
                $this->definition('sheet', 'question-sheet'),
            ]),
        ]);

        $index = (new PageTreeService())->indexExcludingChildrenOf($root, ['forum-question']);

        $this->assertSame(['root/unit', 'root/unit/sheet'], $index->keys());
    }
}
