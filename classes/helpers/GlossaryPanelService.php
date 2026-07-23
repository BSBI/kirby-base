<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

use BSBI\WebBase\models\GlossaryList;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Content\Field;

/**
 * Powers the panel "Add glossary links" preview/apply UI.
 *
 * Preview walks a page's content fields (layout or plain blocks JSON)
 * collecting glossary term matches with surrounding context for editor
 * review. Apply materialises the editor-confirmed links into the block JSON
 * as page:// permalinks via GlossaryLinkApplier and persists the page.
 * Both operations share GlossaryMatcherService, so what the editor previews
 * is exactly what apply will link.
 */
final readonly class GlossaryPanelService
{
    /**
     * Constructor.
     *
     * @param App $kirby The Kirby application instance
     * @param GlossaryService $glossaryService The glossary service
     * @param GlossaryMatcherService $matcher The term matcher
     * @param GlossaryLinkApplier $applier The link applier
     */
    public function __construct(
        private App $kirby,
        private GlossaryService $glossaryService,
        private GlossaryMatcherService $matcher = new GlossaryMatcherService(),
        private GlossaryLinkApplier $applier = new GlossaryLinkApplier(),
    ) {
    }

    /**
     * Get the content field names scanned for glossary links.
     *
     * @return string[]
     */
    public function getContentFieldNames(): array
    {
        $fields = $this->kirby->option('glossary.contentFields', ['mainContent']);
        return is_array($fields) ? array_values(array_filter($fields, 'is_string')) : ['mainContent'];
    }

    /**
     * Preview the glossary links that could be added to a page.
     *
     * @param Page $page The page to scan
     * @return array<int, array{field: string, blockId: string, term: string, matchedText: string, contextBefore: string, contextAfter: string}>
     */
    public function previewForPage(Page $page): array
    {
        $glossary = $this->glossaryService->getGlossary();

        if (!$glossary->hasListItems()) {
            return [];
        }

        $terms = $glossary->getTerms();
        $matches = [];

        foreach ($this->getContentFieldNames() as $fieldName) {
            $structure = $this->decodeFieldJson($page, $fieldName);

            $this->walkTextBlocks($structure, function (array $block) use ($terms, $fieldName, &$matches): array {
                $blockId = is_string($block['id'] ?? null) ? $block['id'] : '';
                $text = $this->getBlockText($block);

                if ($text !== '') {
                    foreach ($this->matcher->findMatches($terms, $text, $blockId) as $match) {
                        $matches[] = [
                            'field' => $fieldName,
                            'blockId' => $match->getBlockId(),
                            'term' => $match->getTerm(),
                            'matchedText' => $match->getMatchedText(),
                            'contextBefore' => $match->getContextBefore(),
                            'contextAfter' => $match->getContextAfter(),
                        ];
                    }
                }

                return $block;
            });
        }

        return $matches;
    }

    /**
     * Apply editor-confirmed glossary links to a page and persist it.
     *
     * @param Page $page The page to update
     * @param array<int, array{blockId: string, term: string}> $selections The confirmed links
     * @return int The number of links applied
     * @throws \Throwable When the page update fails
     */
    public function applyToPage(Page $page, array $selections): int
    {
        if ($selections === []) {
            return 0;
        }

        $glossary = $this->glossaryService->getGlossary();
        $applied = 0;
        $updates = [];

        foreach ($this->getContentFieldNames() as $fieldName) {
            $fieldJson = $this->getFieldJson($page, $fieldName);

            if ($fieldJson === '') {
                continue;
            }

            $result = $this->applySelectionsToFieldJson($fieldJson, $selections, $glossary);

            if ($result['applied'] > 0) {
                $updates[$fieldName] = $result['json'];
                $applied += $result['applied'];
            }
        }

        if ($updates !== []) {
            $page->update($updates);
        }

        return $applied;
    }

    /**
     * Apply selections to a single field's JSON (layout or plain blocks).
     * Pure: returns the rewritten JSON and the number of links applied.
     *
     * @param string $fieldJson The field's raw JSON value
     * @param array<int, array{blockId: string, term: string}> $selections The confirmed links
     * @param GlossaryList $glossary The glossary items
     * @return array{json: string, applied: int}
     */
    public function applySelectionsToFieldJson(string $fieldJson, array $selections, GlossaryList $glossary): array
    {
        $structure = json_decode($fieldJson, true);

        if (!is_array($structure)) {
            return ['json' => $fieldJson, 'applied' => 0];
        }

        $termsByBlock = $this->groupSelectionsByBlock($selections, $glossary);

        if ($termsByBlock === []) {
            return ['json' => $fieldJson, 'applied' => 0];
        }

        $applied = 0;

        $structure = $this->walkTextBlocks(
            $structure,
            function (array $block) use ($termsByBlock, &$applied): array {
                $blockId = is_string($block['id'] ?? null) ? $block['id'] : '';
                $text = $this->getBlockText($block);

                if ($blockId === '' || !isset($termsByBlock[$blockId]) || $text === '') {
                    return $block;
                }

                $newText = $this->applier->applyLinks($text, $termsByBlock[$blockId]);

                if ($newText !== $text) {
                    $content = is_array($block['content'] ?? null) ? $block['content'] : [];
                    $content['text'] = $newText;
                    $block['content'] = $content;
                    $applied += count($termsByBlock[$blockId]);
                }

                return $block;
            }
        );

        if ($applied === 0) {
            return ['json' => $fieldJson, 'applied' => 0];
        }

        $encoded = json_encode($structure, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return [
            'json' => $encoded === false ? $fieldJson : $encoded,
            'applied' => $applied,
        ];
    }

    /**
     * Group selections into a per-block map of term => item UUID, dropping
     * selections whose term is not in the glossary.
     *
     * @param array<int, array{blockId: string, term: string}> $selections The confirmed links
     * @param GlossaryList $glossary The glossary items
     * @return array<string, array<string, string>> Map of blockId => [term => uuid]
     */
    private function groupSelectionsByBlock(array $selections, GlossaryList $glossary): array
    {
        $termsByBlock = [];

        foreach ($selections as $selection) {
            $blockId = $selection['blockId'];
            $term = $selection['term'];

            if ($blockId === '' || $term === '') {
                continue;
            }

            $item = $glossary->findByTerm($term);

            if ($item === null || !$item->hasUuid()) {
                continue;
            }

            $termsByBlock[$blockId][$item->getTitle()] = $item->getUuid();
        }

        return $termsByBlock;
    }

    /**
     * Walk every text/list block in a decoded layout or blocks structure,
     * passing each block through the callback (which may return it modified).
     *
     * @param array<int|string, mixed> $structure The decoded field JSON
     * @param callable(array<string, mixed>): array<string, mixed> $callback The per-block transform
     * @return array<int|string, mixed> The (possibly modified) structure
     */
    private function walkTextBlocks(array $structure, callable $callback): array
    {
        foreach ($structure as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            if (isset($item['columns']) && is_array($item['columns'])) {
                // layout row: recurse into each column's blocks
                $columns = $item['columns'];
                foreach ($columns as $columnIndex => $column) {
                    if (is_array($column) && isset($column['blocks']) && is_array($column['blocks'])) {
                        $column['blocks'] = $this->walkTextBlocks($column['blocks'], $callback);
                        $columns[$columnIndex] = $column;
                    }
                }
                $item['columns'] = $columns;
                $structure[$index] = $item;
                continue;
            }

            $type = $item['type'] ?? '';
            $isHidden = $item['isHidden'] ?? false;

            if (in_array($type, ['text', 'list'], true) && $isHidden !== true) {
                /** @var array<string, mixed> $item */
                $structure[$index] = $callback($item);
            }
        }

        return $structure;
    }

    /**
     * Decode a page field's JSON value, returning an empty structure when the
     * field is missing or invalid.
     *
     * @param Page $page The page to read from
     * @param string $fieldName The field name
     * @return array<int|string, mixed>
     */
    private function decodeFieldJson(Page $page, string $fieldName): array
    {
        $decoded = json_decode($this->getFieldJson($page, $fieldName), true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Get a page field's raw JSON value.
     *
     * @param Page $page The page to read from
     * @param string $fieldName The field name
     * @return string
     */
    private function getFieldJson(Page $page, string $fieldName): string
    {
        $field = $page->content()->get($fieldName);

        if (!$field instanceof Field) {
            return '';
        }

        $value = $field->value();
        return is_string($value) ? $value : '';
    }

    /**
     * Get a block's text content, or an empty string when absent.
     *
     * @param array<string, mixed> $block The decoded block
     * @return string
     */
    private function getBlockText(array $block): string
    {
        $content = $block['content'] ?? null;

        if (!is_array($content)) {
            return '';
        }

        $text = $content['text'] ?? '';
        return is_string($text) ? $text : '';
    }
}
