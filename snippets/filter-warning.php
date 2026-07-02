<?php

declare(strict_types=1);

use BSBI\WebBase\models\BaseFilter;

/**
 * Displays a "Results are being filtered" warning at the top of a filter form
 * when the supplied filter model has an active description. Rendered as a
 * collapsed details/summary accordion which expands to list the active
 * filter values.
 *
 * @var BaseFilter $filters The filter model for the current list
 */

if (!isset($filters) || !($filters instanceof BaseFilter) || !$filters->hasDescription()) :
    return;
endif;
?>
<details class="filter-warning alert alert-warning py-2 px-3 mb-3">
  <summary class="fw-bold">Results are being filtered</summary>
  <ul class="mb-0 mt-2">
    <?php foreach ($filters->getDescription() as $descriptionLine) : ?>
      <li><?= esc($descriptionLine) ?></li>
    <?php endforeach ?>
  </ul>
</details>
