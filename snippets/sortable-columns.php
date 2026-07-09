<?php

declare(strict_types=1);

use BSBI\WebBase\models\BaseList;

/**
 * Renders a sortable <th> element for a table column.
 *
 * Parameters:
 *   string    $label      Display label for the column header
 *   string    $columnKey  The sort key for this column (must match a value in $list->getSortableColumns() to be sortable)
 *   BaseList  $list       The model list, carrying current sort state and allowed sortable columns
 */

if (!isset($label) || !isset($columnKey) || !isset($list)) {
    /** @noinspection PhpUnhandledExceptionInspection */
    throw new Exception('sortable-columns snippet requires $label, $columnKey, and $list');
}

/** @var BaseList $list */
/** @var string $label */
/** @var string $columnKey */

$sortDirection = $list->getSortDirectionForColumn($columnKey);
$ariaSort = $list->getAriaSortForColumn($columnKey);
?>
<th scope="col"<?= $ariaSort !== '' ? ' aria-sort="' . htmlspecialchars($ariaSort, ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
    <?php if ($list->isSortableColumn($columnKey)) : ?>
        <a href="<?= htmlspecialchars($list->getSortUrl($columnKey), ENT_QUOTES, 'UTF-8') ?>" style="white-space: nowrap">
            <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
            <?php if ($sortDirection === 'asc') : ?>
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16" aria-label="sorted ascending" role="img"><path fill-rule="evenodd" d="M8 15a.5.5 0 0 0 .5-.5V2.707l3.146 3.147a.5.5 0 0 0 .708-.708l-4-4a.5.5 0 0 0-.708 0l-4 4a.5.5 0 1 0 .708.708L7.5 2.707V14.5a.5.5 0 0 0 .5.5z"/></svg>
            <?php elseif ($sortDirection === 'desc') : ?>
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16" aria-label="sorted descending" role="img"><path fill-rule="evenodd" d="M8 1a.5.5 0 0 1 .5.5v11.793l3.146-3.147a.5.5 0 0 1 .708.708l-4 4a.5.5 0 0 1-.708 0l-4-4a.5.5 0 0 1 .708-.708L7.5 13.293V1.5A.5.5 0 0 1 8 1z"/></svg>
            <?php else : ?>
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16" aria-label="sortable" role="img"><path fill-rule="evenodd" d="M11.5 15a.5.5 0 0 0 .5-.5V2.707l3.146 3.147a.5.5 0 0 0 .708-.708l-4-4a.5.5 0 0 0-.708 0l-4 4a.5.5 0 1 0 .708.708L11 2.707V14.5a.5.5 0 0 0 .5.5zm-7-14a.5.5 0 0 1 .5.5v11.793l3.146-3.147a.5.5 0 0 1 .708.708l-4 4a.5.5 0 0 1-.708 0l-4-4a.5.5 0 0 1 .708-.708L4 13.293V1.5A.5.5 0 0 1 4.5 1z"/></svg>
            <?php endif ?>
        </a>
    <?php else : ?>
        <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
    <?php endif ?>
</th>
