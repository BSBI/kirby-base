<?php

declare(strict_types=1);

// glossary items are not standalone pages: their URLs redirect to the anchor
// on the parent glossary listing page (fragments cannot survive permalink
// resolution, so in-content glossary links target the item pages directly)
$currentPage = page();
go(($currentPage?->parent()?->url() ?? site()->url()) . '#' . ($currentPage?->slug() ?? ''));
