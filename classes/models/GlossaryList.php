<?php

declare(strict_types=1);

namespace BSBI\WebBase\models;

/**
 * Class GlossaryList
 * A collection of glossary items with lookups used by glossary matching and
 * link enrichment.
 *
 * @package BSBI\WebBase
 * @extends BaseList<GlossaryItem, BaseFilter>
 */
class GlossaryList extends BaseList
{
    /**
     * Add a glossary item
     * @param GlossaryItem $item
     * @return GlossaryList
     */
    public function addListItem(GlossaryItem $item): self
    {
        $this->add($item);
        return $this;
    }

    /**
     * Get all glossary terms (item titles) in list order
     * @return string[]
     */
    public function getTerms(): array
    {
        return array_map(
            static fn (GlossaryItem $item): string => $item->getTitle(),
            $this->list
        );
    }

    /**
     * Find an item by its term (case-insensitive)
     * @param string $term
     * @return GlossaryItem|null
     */
    public function findByTerm(string $term): ?GlossaryItem
    {
        foreach ($this->list as $item) {
            if (strcasecmp($item->getTitle(), $term) === 0) {
                return $item;
            }
        }
        return null;
    }

    /**
     * Find an item by its URL
     * @param string $url
     * @return GlossaryItem|null
     */
    public function findByUrl(string $url): ?GlossaryItem
    {
        foreach ($this->list as $item) {
            if ($item->getUrl() === $url) {
                return $item;
            }
        }
        return null;
    }

    /**
     * Find an item by its slug
     * @param string $slug
     * @return GlossaryItem|null
     */
    public function findBySlug(string $slug): ?GlossaryItem
    {
        foreach ($this->list as $item) {
            if ($item->getSlug() === $slug) {
                return $item;
            }
        }
        return null;
    }

    /**
     * @return string
     */
    function getItemType(): string
    {
        return GlossaryItem::class;
    }

    /**
     * @return string
     */
    function getFilterType(): string
    {
        return BaseFilter::class;
    }
}
