<?php

/**
 * Class WebPageLinks
 * Represents a collection of web page links and provides functionality
 * for managing and retrieving these links.
 */
namespace BSBI\WebBase\models;


/**
 * @extends BaseList<WebPageLink, BaseFilter>
 */
class WebPageLinks extends BaseList
{

    /**
     * Add a web page
     * @param WebPageLink $link
     * @return WebPageLinks
     */
    public function addListItem(WebPageLink $link): self
    {
        if ($link->didComplete()) {
            $this->add($link);
        }
        return $this;
    }

    /**
     * Find the page with the matching link type
     * @param string $linkType
     * @return WebPageLink
     */
    public function getLink(string $linkType): WebPageLink
    {
        $matchingLink = array_filter($this->list, function ($item) use ($linkType) {
            if ($item instanceof WebPageLink) {
                return $item->getPageType() === $linkType;
            }
            return false;
        });

        if (count($matchingLink) === 0) {
            $linkNotFound = new WebPageLink('', '', 'NOT_FOUND', 'NOT_FOUND');
            $linkNotFound->setStatus(false);
            return $linkNotFound;
        }
        return reset($matchingLink);
    }

    public function hasLinksIncludedInMenus(): bool
    {
        $linksIncludedInMenus = array_filter($this->list, function ($item) {
            if ($item instanceof WebPageLink) {
                return $item->doIncludeInMenus();
            }
            return false;
        });
        return (count($linksIncludedInMenus) > 0);
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
