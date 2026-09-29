<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\forms\panel;

use BSBI\WebBase\forms\panel\SectionPageResolver;
use Kirby\Cms\Page;

/**
 * Test double: finds section pages in a fixed map by reference.
 */
final readonly class InMemorySectionPageResolver implements SectionPageResolver
{
    /**
     * @param array<string, Page> $pages Reference => page
     */
    public function __construct(private array $pages)
    {
    }

    /**
     * Returns the mapped page, or null.
     *
     * @param string $reference
     */
    public function resolve(string $reference): ?Page
    {
        return $this->pages[$reference] ?? null;
    }
}
