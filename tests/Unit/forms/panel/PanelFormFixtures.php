<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\forms\panel;

use BSBI\WebBase\forms\panel\SectionPageResolver;
use BSBI\WebBase\Testing\KirbyContentBuilder;
use Kirby\Cms\Block;
use Kirby\Cms\Page;
use Kirby\Data\Json;

/**
 * Builders for panel-built form content: field blocks, section pages and
 * form pages, plus an in-memory section resolver.
 */
trait PanelFormFixtures
{
    /**
     * Returns the raw array for one block, as stored in a blocks field.
     *
     * @param string               $type    Block type, e.g. 'form-textbox'
     * @param array<string, mixed> $content Block content fields
     * @param string|null          $id      Block id; generated when null
     * @return array<string, mixed>
     */
    private function blockData(string $type, array $content, ?string $id = null): array
    {
        return [
            'id'       => $id ?? $this->uuid(),
            'type'     => $type,
            'isHidden' => false,
            'content'  => $content,
        ];
    }

    /**
     * Returns a single Kirby Block built from raw block data.
     *
     * @param array<string, mixed> $data
     */
    private function block(array $data): Block
    {
        $block = (new KirbyContentBuilder())->block($data);
        $this->assertInstanceOf(Block::class, $block);
        return $block;
    }

    /**
     * Returns a library section page.
     *
     * @param array<int, array<string, mixed>> $fieldBlocks Raw field block data
     * @param string                           $legend      Visible section title
     * @param string|null                      $extends     Reference to a base section
     * @param string|null                      $slug        Page slug (also its id)
     */
    private function sectionPage(
        array $fieldBlocks,
        string $legend = '',
        ?string $extends = null,
        ?string $slug = null
    ): Page {
        $content = [
            'legend'     => $legend,
            'formFields' => Json::encode($fieldBlocks),
        ];
        if ($extends !== null) {
            $content['extends'] = "- " . $extends;
        }
        return (new KirbyContentBuilder())->page($content, $slug);
    }

    /**
     * Returns a form page whose formSections blocks field holds the given blocks.
     *
     * @param array<int, array<string, mixed>> $sectionBlocks Raw section block data
     */
    private function formPage(array $sectionBlocks): Page
    {
        return (new KirbyContentBuilder())->page(['formSections' => Json::encode($sectionBlocks)]);
    }

    /**
     * Returns raw data for a block referencing a library section.
     *
     * @param string $reference   Section reference as stored by a pages field
     * @param string $title       Optional legend override
     * @param string $showField   Optional controlling field key
     * @param string $showValue   Optional controlling value
     * @param string|null $id     Block id
     * @return array<string, mixed>
     */
    private function sectionRef(
        string $reference,
        string $title = '',
        string $showField = '',
        string $showValue = '',
        ?string $id = null
    ): array {
        return $this->blockData('form-section-ref', [
            'section'       => "- " . $reference,
            'title'         => $title,
            'showWhenField' => $showField,
            'showWhenValue' => $showValue,
        ], $id);
    }

    /**
     * Returns raw data for an inline section block.
     *
     * @param array<int, array<string, mixed>> $fieldBlocks Raw field block data
     * @param string      $title     Visible section title
     * @param string      $showField Optional controlling field key
     * @param string      $showValue Optional controlling value
     * @param string|null $id        Block id
     * @return array<string, mixed>
     */
    private function sectionInline(
        array $fieldBlocks,
        string $title = '',
        string $showField = '',
        string $showValue = '',
        ?string $id = null
    ): array {
        return $this->blockData('form-section-inline', [
            'title'         => $title,
            'formFields'    => Json::encode($fieldBlocks),
            'showWhenField' => $showField,
            'showWhenValue' => $showValue,
        ], $id);
    }

    /**
     * Returns a resolver that finds pages in the given map by reference.
     *
     * @param array<string, Page> $pages Reference => page
     */
    private function resolver(array $pages): SectionPageResolver
    {
        return new InMemorySectionPageResolver($pages);
    }

    /**
     * Returns a random v4-style UUID, the format Kirby gives block ids.
     */
    private function uuid(): string
    {
        $hex = bin2hex(random_bytes(16));
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }
}
