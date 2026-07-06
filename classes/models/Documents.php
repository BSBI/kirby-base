<?php

namespace BSBI\WebBase\models;


/**
 * @package BSBI\Web
 * @extends BaseList<Document, BaseFilter>
 */
class Documents extends BaseList
{

    /**
     * Add a link
     * @param Document $link
     */
    public function addListItem(Document $link): void
    {
        $this->add($link);
    }


    /**
     * @return string
     */
    function getItemType(): string
    {
        return Document::class;
    }

    /**
     * @return string
     */
    function getFilterType(): string
    {
        return BaseFilter::class;
    }
}
