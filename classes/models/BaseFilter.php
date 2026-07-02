<?php

namespace BSBI\WebBase\models;

use BSBI\WebBase\traits\ErrorHandling;
use BSBI\WebBase\traits\OptionsHandling;

/**
 * Class EventCategories
 * Represents a BSBI event category list with various properties and methods.
 *
 * @package BSBI\Web
 */
abstract class BaseFilter
{
    use ErrorHandling;
    use OptionsHandling;

    /** Exclusion key for the keyword description line */
    public const string DESCRIBE_KEYWORDS = 'keywords';

    /** @var string[] */
    private array $description;

    /** @var string[] Description keys suppressed because their values are page-scoped or programmatic */
    private array $descriptionExclusions = [];

    private string $keywords = '';

    private bool $stopPagination = false;


    public function hasDescription(): bool
    {
        return isset($this->description) && count($this->description)>0;
    }

    /**
     * Rebuilds the filter description from the currently active, user-driven
     * filter values. Clears any previously built description first, so the
     * method is idempotent and safe to call from more than one code path.
     *
     * Subclasses supply their lines via describeActiveFilters(); the keyword
     * line is handled here as keywords live on BaseFilter.
     *
     * @return static
     */
    public function buildDescription(): static
    {
        $this->description = [];
        if (!$this->isExcludedFromDescription(self::DESCRIBE_KEYWORDS) && $this->hasKeywords()) {
            $this->addToDescription('Keyword(s): ' . $this->getKeywords());
        }
        foreach ($this->describeActiveFilters() as $line) {
            $this->addToDescription($line);
        }
        return $this;
    }

    /**
     * Returns one human-readable line per active, user-driven filter value.
     * Subclasses override this; the default (no describable filters) suits
     * keyword-only filters.
     *
     * @return string[]
     */
    protected function describeActiveFilters(): array
    {
        return [];
    }

    /**
     * Marks description keys as excluded because their values are page-scoped
     * or programmatic rather than user-driven (e.g. an events listing page
     * that pre-sets its event types).
     *
     * @param string ...$keys Description keys to suppress
     * @return static
     */
    public function excludeFromDescription(string ...$keys): static
    {
        $this->descriptionExclusions = array_merge($this->descriptionExclusions, $keys);
        return $this;
    }

    /**
     * @param string $key Description key to check
     * @return bool True when the key must not be described
     */
    public function isExcludedFromDescription(string $key): bool
    {
        return in_array($key, $this->descriptionExclusions, true);
    }

    /**
     * @return string []
     */
    public function getDescription(): array
    {
        return $this->description;
    }

    /**
     * @param string $description
     * @return BaseFilter
     */
    public function addToDescription(string $description): BaseFilter
    {
        $this->description [] = $description;
        return $this;
    }

    public function hasKeywords(): bool
    {
        return !empty($this->keywords);
    }

    /**
     * @return string
     */
    public function getKeywords(): string
    {
        return $this->keywords;
    }

    /**
     * @param string $keywords
     * @return BaseFilter
     */
    public function setKeywords(string $keywords): BaseFilter
    {
        $this->keywords = $keywords;
        return $this;
    }

    /**
     * @return bool
     */
    public function doStopPagination(): bool
    {
        return $this->stopPagination;
    }

    /**
     * @param bool $stopPagination
     * @return BaseFilter
     */
    public function setStopPagination(bool $stopPagination): BaseFilter
    {
        $this->stopPagination = $stopPagination;
        return $this;
    }


}