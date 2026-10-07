<?php

declare(strict_types=1);

namespace BSBI\WebBase\forms\panel;

use Kirby\Cms\Page;

/**
 * Resolves section references as another resolver does, except that one
 * section is swapped for a stand-in: used to read forms as they would be
 * after a library section's pending edit is saved.
 */
final readonly class SectionOverrideResolver implements SectionPageResolver
{
    /**
     * @param SectionPageResolver $resolver    Resolves every other reference
     * @param Page                $replacement Stands in for the page with its id
     */
    public function __construct(private SectionPageResolver $resolver, private Page $replacement)
    {
    }

    /**
     * Returns the referenced page, or the stand-in if it is the replaced one.
     *
     * @param string $reference A `page://` UUID or page id
     */
    public function resolve(string $reference): ?Page
    {
        $page = $this->resolver->resolve($reference);
        return $page !== null && $page->id() === $this->replacement->id() ? $this->replacement : $page;
    }
}
