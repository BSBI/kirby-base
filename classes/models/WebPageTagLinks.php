<?php

namespace BSBI\WebBase\models;

/**
 * @extends BaseList<WebPageTagLinkSet, BaseFilter>
 */
class WebPageTagLinks extends BaseList
{


    /**
     * @param WebPageTagLinkSet $item
     * @return $this
     */
    public function addListItem(WebPageTagLinkSet $item): self {
        $this->add($item);
        return $this;
    }

    /**
     * @return string
     */
    function getItemType(): string
    {
        return WebPageTagLinkSet::class;
    }

    /**
     * @return string
     */
    function getFilterType(): string
    {
        return BaseFilter::class;
    }
}