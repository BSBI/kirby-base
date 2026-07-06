<?php

namespace BSBI\WebBase\models;

/**
 * @extends BaseList<WebPageLink, BaseFilter>
 */
class OnThisPageLinks extends BaseList
{

    /**
     * Add a web page
     * @param OnThisPageLink $link
     * @return $this
     */
    public function addListItem(OnThisPageLink $link): self
    {
        if ($link->didComplete()) {
            $this->add($link);
        }
        return $this;
    }

    /**
     * @param OnThisPageLink $item
     * @return $this
     */
    public function addListItemAfterMain(OnThisPageLink $item): static
    {
        return $this->addListItemAfter($item, 'main');
    }

    /**
     * @param OnThisPageLink $item
     * @return $this
     */
    public function addListItemAfterLower(OnThisPageLink $item): static
    {
        return $this->addListItemAfter($item, 'lower');
    }

    /**
     * @param OnThisPageLink $item
     * @param string $linkArea
     * @return $this
     */
    private function addListItemAfter(OnThisPageLink $item, string $linkArea): static
    {
        $lastMainIndex = -1;

        foreach ($this->list as $index => $listItem) {
            /** @var OnThisPageLink $listItem */
            if ($listItem->getLinkArea() === $linkArea) {
                $lastMainIndex = $index;
            }
        }

        if ($lastMainIndex === -1) {
            $this->list[] = $item;
        } else {
            array_splice($this->list, $lastMainIndex + 1, 0, [$item]);
        }

        return $this;
    }

    /**
     * @return string
     */
    function getItemType(): string
    {
        return WebPageLink::class;
    }

    /**
     * @return string
     */
    function getFilterType(): string
    {
        return BaseFilter::class;
    }
}
