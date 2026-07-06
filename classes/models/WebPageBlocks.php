<?php

namespace BSBI\WebBase\models;


/**
 * @extends BaseList<WebPageBlock, BaseFilter>
 */
class WebPageBlocks extends BaseList
{

    /**
     * Add a web page
     * @param WebPageBlock $block
     */
    public function addListItem(WebPageBlock $block): void
    {
        $this->add($block);
    }

    /**
     * Do the blocks contain a block of a specified type?
     * @param string $blockType
     * @return bool
     */
    public function hasBlockOfType(string $blockType): bool
    {
        $matchingBlock = array_filter($this->list, function ($item) use ($blockType) {
            if ($item instanceof WebPageBlock) {
                return $item->getBlockType() === $blockType;
            }
            return false;
        });
        return count($matchingBlock) > 0;
    }

    public function hasBlockTypeStarting(string $blockTypeStart): bool
    {
        $matchingBlock = array_filter($this->list, function ($item) use ($blockTypeStart) {
            if ($item instanceof WebPageBlock) {
                return str_starts_with($item->getBlockType(),$blockTypeStart);
            }
            return false;
        });
        return count($matchingBlock) > 0;
    }

    /**
     * @return string
     */
    public function getAllContentAsHTML(): string {
        $html = '';
        foreach ($this->list as $block) {
            /** @noinspection PhpPossiblePolymorphicInvocationInspection */
            $html.= $block->getBlockContent();
        }
        return $html;
    }

    /**
     * @return string
     */
    function getItemType(): string
    {
        return WebPageBlock::class;
    }

    /**
     * @return string
     */
    function getFilterType(): string
    {
        return BaseFilter::class;
    }
}
