<?php

namespace BSBI\WebBase\models;

/**
 * @extends BaseList<Image, BaseFilter>
 */
class ImageList extends BaseList
{

    /**
     * @param Image $image
     */
    public function addListItem(Image $image): void
    {
        $this->add($image);
    }

    /**
     * @return string
     */
    function getItemType(): string
    {
        return Image::class;
    }

    /**
     * @return string
     */
    function getFilterType(): string
    {
        return BaseFilter::class;
    }
}
