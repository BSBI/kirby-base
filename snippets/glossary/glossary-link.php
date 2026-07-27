<?php

declare(strict_types=1);

use BSBI\WebBase\helpers\GlossaryService;

/**
 * Renders a glossary link for a term inside hand-written snippet/template
 * content, with the same tooltip behaviour as glossary links in block
 * content. Falls back to plain text when the glossary is disabled or the
 * term is not found, so content never breaks.
 *
 * Usage: snippet('glossary/glossary-link', ['term' => 'bract'])
 *        snippet('glossary/glossary-link', ['term' => 'bract', 'label' => 'bracts'])
 *
 * @var string $term The glossary term (matched case-insensitively against item titles)
 * @var string|null $label Optional link text (defaults to the term as written)
 */

$term = $term ?? '';
$label = $label ?? null;

$glossaryService = GlossaryService::instance(kirby(), kirby()->site());
$glossaryItem = $glossaryService->isEnabled() && $term !== ''
    ? $glossaryService->getGlossary()->findByTerm($term)
    : null;

if ($glossaryItem !== null) {
    echo $glossaryItem->getLinkHtml($label ?? $term);
} else {
    echo esc($label ?? $term);
}
