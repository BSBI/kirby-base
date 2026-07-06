<?php

namespace BSBI\WebBase\models;


/**
 * Class CoreLinks
 * Represents a BSBI core link (e.g. Contact Page) with various properties and methods.
 * @package BSBI\Web
 * @extends BaseList<CoreLink, BaseFilter>
 */
class CoreLinks extends BaseList
{

    /**
     * Add a link
     * @param CoreLink $link
     * @return CoreLinks
     */
    public function addListItem(CoreLink $link): self
    {
        $this->add($link);
        return $this;
    }

    /**
     * Find the page with the matching link type
     * @param string $linkType
     * @return CoreLink
     */
    public function getPage(string $linkType): CoreLink
    {
        $matchingPage = array_filter($this->list, function ($item) use ($linkType) {
            if ($item instanceof CoreLink) {
                return $item->getType() === $linkType;
            }
            return false;
        });

        if (count($matchingPage) === 0) {
            $linkNotFound = new CoreLink('','', 'NOT_FOUND');
            $linkNotFound->setStatus(false);
            return $linkNotFound;
        }
        return reset($matchingPage);
    }

    /**
     * @return string
     */
    function getItemType(): string
    {
        return CoreLink::class;
    }

    /**
     * @return string
     */
    function getFilterType(): string
    {
        return BaseFilter::class;
    }
}
