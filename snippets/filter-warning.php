<?php

declare(strict_types=1);

use BSBI\WebBase\helpers\FilterResetService;
use BSBI\WebBase\models\BaseFilter;

/**
 * Displays a "Results are being filtered" warning above a filtered results
 * listing when the supplied filter model has an active description. Rendered
 * as a collapsed details/summary accordion which expands to list the active
 * filter values, with a "Remove all filters" link that resets the persisted
 * filters to their defaults (via the clearFilters request parameter).
 *
 * @var BaseFilter $filters The filter model for the current list
 * @var string|null $resetUrl Optional override for the reset link target
 */

if (!isset($filters) || !($filters instanceof BaseFilter) || !$filters->hasDescription()) :
    return;
endif;

if (!isset($resetUrl)) :
    $resetUrl = page() !== null
        ? page()->url() . '?' . FilterResetService::RESET_PARAM . '=1'
        : null;
endif;
?>
<div class="filter-warning alert alert-warning py-2 px-3 mb-3 d-flex flex-wrap justify-content-between align-items-start gap-2">
  <details class="flex-grow-1">
    <summary class="fw-bold">Results are being filtered</summary>
    <ul class="mb-0 mt-2">
      <?php foreach ($filters->getDescription() as $descriptionLine) : ?>
        <li><?= esc($descriptionLine) ?></li>
      <?php endforeach ?>
    </ul>
  </details>
  <?php if ($resetUrl !== null) : ?>
    <a class="btn btn-sm btn-outline-secondary text-nowrap" href="<?= esc($resetUrl, 'attr') ?>">Remove all filters</a>
  <?php endif ?>
</div>
