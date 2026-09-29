<?php

declare(strict_types=1);

namespace BSBI\WebBase\forms\panel;

use Kirby\Cms\App;
use Kirby\Cms\Page;

/**
 * Resolves section references through Kirby, so `page://` UUIDs go through
 * Kirby's UUID cache the same way every pages field does. Drafts are included:
 * a library section need not be published to be used on a form.
 */
final readonly class KirbySectionPageResolver implements SectionPageResolver
{
    /**
     * @param App $kirby The Kirby app to look pages up in
     */
    public function __construct(private App $kirby)
    {
    }

    /**
     * Returns the referenced page, or null if it cannot be found.
     *
     * @param string $reference A `page://` UUID or page id
     */
    public function resolve(string $reference): ?Page
    {
        return $this->kirby->page($reference);
    }
}
