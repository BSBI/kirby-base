<?php

namespace BSBI\WebBase\models;


/**
 * Class RelatedContentList
 *
 * Represents a list of related content. This class provides functionalities
 * to add related content items to the list, retrieve the list of items,
 * and specify the types of items and filters used in the list.
 * @extends BaseList<RelatedContent, BaseFilter>
 */
class RelatedContentList extends BaseList
{
    /**
     * Add a clink
     * @param RelatedContent $relatedContent
     */
    public function addListItem(RelatedContent $relatedContent): void
    {
        $this->add($relatedContent);
    }

    /**
     * @return string
     */
    function getItemType(): string
    {
        return RelatedContent::class;
    }

    /**
     * @return string
     */
    function getFilterType(): string
    {
        return BaseFilter::class;
    }
}
