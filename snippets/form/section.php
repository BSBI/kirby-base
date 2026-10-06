<?php

declare(strict_types=1);

use BSBI\WebBase\forms\ResolvedFormField;
use BSBI\WebBase\forms\ResolvedFormSection;

/**
 * Renders a form section, with data attributes for client-side conditional
 * show/hide. A titled section is a <fieldset> named by its <legend>; an
 * untitled one is a plain <div>, because a fieldset with no legend is an
 * unnamed group to a screen reader (WCAG 1.3.1).
 *
 * Expected variable:
 *   $section  ResolvedFormSection  The resolved section to render
 */

if (!isset($section) || !$section instanceof ResolvedFormSection) :
    /** @noinspection PhpUnhandledExceptionInspection */
    throw new Exception('form/section snippet: $section must be a ResolvedFormSection');
endif;

$attrs = 'class="form-section"';
$attrs .= ' id="section-' . htmlspecialchars($section->id, ENT_QUOTES, 'UTF-8') . '"';

if ($section->isConditional()) :
    $attrs .= ' data-condition-field="' . htmlspecialchars((string) $section->conditionField, ENT_QUOTES, 'UTF-8') . '"';
    $attrs .= ' data-condition-value="' . htmlspecialchars((string) $section->conditionValue, ENT_QUOTES, 'UTF-8') . '"';
    $attrs .= ' style="display:none"';
endif;

$tag = $section->title !== '' ? 'fieldset' : 'div';

?>
<<?= $tag ?> <?= $attrs ?>>
    <?php if ($section->title !== '') : ?>
        <legend class="fw-semibold mb-2"><?= htmlspecialchars($section->title, ENT_QUOTES, 'UTF-8') ?></legend>
    <?php endif; ?>

    <?php foreach ($section->fields as $field) :
        if (!$field instanceof ResolvedFormField) :
            continue;
        endif; ?>
        <div class="mb-3">
            <?php snippet('form/field-' . $field->type, ['field' => $field]) ?>
        </div>
        <hr>
    <?php endforeach; ?>
</<?= $tag ?>>
