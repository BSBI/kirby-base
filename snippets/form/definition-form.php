<?php

declare(strict_types=1);

use BSBI\WebBase\forms\ResolvedForm;
use BSBI\WebBase\forms\ResolvedFormField;
use BSBI\WebBase\forms\ResolvedFormSection;

/**
 * Generic layout for definition-based forms.
 *
 * Renders fixed fields (and sections) from a BaseFormDefinition followed by
 * any custom form element blocks added by panel editors, then a submit button.
 *
 * Sections with a showWhen() condition are hidden by default and revealed
 * client-side when the controlling radio or select field matches the expected value.
 *
 * Expected variable:
 *   $form  ResolvedForm  Form data built from FormProperties::getResolvedForm()
 */

if (!isset($form) || !$form instanceof ResolvedForm) :
    /** @noinspection PhpUnhandledExceptionInspection */
    throw new Exception('definition-form snippet: $form must be a ResolvedForm');
endif;

if ($form->submissionSuccessful) :
    return;
endif;

?>
<?php if ($form->introHtml !== '') : ?>
<div class="container mb-4">
    <?= $form->introHtml ?>
</div>
<?php endif ?>
<div class="container bg-light py-4 mb-2">
    <form method="post" novalidate>
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($form->csrfToken, ENT_QUOTES, 'UTF-8') ?>">

        <?php
        $hasRequiredFields = false;
        foreach ($form->fieldGroups as $item) {
            if ($item instanceof ResolvedFormField && $item->required) {
                $hasRequiredFields = true;
                break;
            }
            if ($item instanceof ResolvedFormSection) {
                foreach ($item->fields as $field) {
                    if ($field->required) {
                        $hasRequiredFields = true;
                        break 2;
                    }
                }
            }
        }
        ?>
        <?php if ($hasRequiredFields) : ?>
        <p class="text-muted small mb-3">Fields marked <span class="text-danger" aria-hidden="true">*</span><span class="visually-hidden">with an asterisk</span> are required.</p>
        <?php endif; ?>

        <?php foreach ($form->fieldGroups as $item) : ?>
            <?php if ($item instanceof ResolvedFormSection) : ?>
                <?php snippet('form/section', ['section' => $item]) ?>
            <?php elseif ($item instanceof ResolvedFormField) : ?>
                <div class="mb-3">
                    <?php snippet('form/field-' . $item->type, ['field' => $item]) ?>
                </div>
                <hr>
            <?php endif; ?>
        <?php endforeach; ?>

        <?php
        if ($form->customBlocks !== null && $form->customBlocks->isNotEmpty()) :
            foreach ($form->customBlocks as $block) :?>
                <div class="mb-3">
                    <?php snippet('blocks/' . $block->type(), ['block' => $block]) ?>
                </div>
                <hr>
            <?php endforeach;
        endif; ?>

        <div class="container text-end">
            <?php snippet('form/button', ['value' => 'Submit']) ?>
        </div>
    </form>
</div>

<script>
(function () {
    'use strict';

    const formContainer = document.currentScript.previousElementSibling;
    const form = formContainer ? formContainer.querySelector('form') : null;
    if (!form) { return; }

    // ── Conditional sections ──────────────────────────────────────────────

    function updateSections() {
        form.querySelectorAll('fieldset[data-condition-field]').forEach(function (section) {
            const conditionField = section.dataset.conditionField;
            const conditionValue = section.dataset.conditionValue;
            const controlEl = form.querySelector('select[name="' + conditionField + '"]');
            let currentValue = '';
            if (controlEl) {
                currentValue = controlEl.value;
            } else {
                const checked = form.querySelector('input[name="' + conditionField + '"]:checked');
                if (checked) { currentValue = checked.value; }
            }
            const shouldShow = (currentValue === conditionValue);
            section.style.display = shouldShow ? '' : 'none';
            section.querySelectorAll('input, select, textarea').forEach(function (el) {
                el.disabled = !shouldShow;
            });
        });
    }

    // ── Validation ────────────────────────────────────────────────────────

    function getFieldContainer(input) {
        return input.closest('.mb-3') || input.closest('.card');
    }

    function validateForm() {
        const errorContainers = [];

        // Required radio/checkbox groups — find each unique name with required input unchecked
        const checkedNames = new Set();
        form.querySelectorAll('input[type="radio"]:checked:not(:disabled), input[type="checkbox"]:checked:not(:disabled)')
            .forEach(function (el) { checkedNames.add(el.name); });

        const seenNames = new Set();
        form.querySelectorAll('input[type="radio"][required]:not(:disabled), input[type="checkbox"][required]:not(:disabled)')
            .forEach(function (el) {
                if (seenNames.has(el.name)) { return; }
                seenNames.add(el.name);
                if (!checkedNames.has(el.name)) {
                    const c = getFieldContainer(el);
                    if (c && !errorContainers.includes(c)) { errorContainers.push(c); }
                }
            });

        // Required rating matrices — at least one row must have a selection
        form.querySelectorAll('[data-rating-matrix-required]').forEach(function (matrix) {
            var hasAnyChecked = matrix.querySelector('input[type="radio"]:checked:not(:disabled)') !== null;
            if (!hasAnyChecked) {
                var c = getFieldContainer(matrix);
                if (c && !errorContainers.includes(c)) { errorContainers.push(c); }
            }
        });

        // Required text inputs and textareas
        form.querySelectorAll('input[required]:not([type="radio"]):not([type="checkbox"]):not(:disabled), textarea[required]:not(:disabled)')
            .forEach(function (el) {
                if (el.value.trim() === '') {
                    const c = getFieldContainer(el);
                    if (c && !errorContainers.includes(c)) { errorContainers.push(c); }
                }
            });

        return errorContainers;
    }

    function clearErrors() {
        form.querySelectorAll('.bsbi-field-error').forEach(function (el) {
            el.classList.remove('bsbi-field-error', 'border', 'border-danger', 'rounded', 'p-3');
            el.style.backgroundColor = '';
        });
        const banner = form.querySelector('.bsbi-validation-banner');
        if (banner) { banner.remove(); }
    }

    function showBanner(count) {
        let banner = form.querySelector('.bsbi-validation-banner');
        if (!banner) {
            banner = document.createElement('div');
            banner.className = 'alert alert-danger bsbi-validation-banner mt-2';
            banner.setAttribute('role', 'alert');
            form.insertBefore(banner, form.firstChild);
        }
        banner.textContent = count === 1
            ? 'Please answer the highlighted question before submitting.'
            : 'Please answer the ' + count + ' highlighted questions before submitting.';
    }

    form.addEventListener('submit', function (e) {
        clearErrors();
        const errors = validateForm();
        if (errors.length > 0) {
            e.preventDefault();
            errors.forEach(function (c) {
                c.classList.add('bsbi-field-error', 'border', 'border-danger', 'rounded', 'p-3');
                c.style.backgroundColor = 'rgba(220, 53, 69, 0.07)';
            });
            showBanner(errors.length);
            const top = errors[0].getBoundingClientRect().top + window.scrollY - 120;
            window.scrollTo({ top: top, behavior: 'smooth' });
        }
    });

    // Clear a field's error highlight only once all its required inputs are answered
    form.addEventListener('change', function (e) {
        const c = getFieldContainer(e.target);
        if (c && c.classList.contains('bsbi-field-error')) {
            const stillFailing = validateForm();
            if (!stillFailing.includes(c)) {
                c.classList.remove('bsbi-field-error', 'border', 'border-danger', 'rounded', 'p-3');
                c.style.backgroundColor = '';
            }
            const remaining = form.querySelectorAll('.bsbi-field-error').length;
            if (remaining === 0) {
                const banner = form.querySelector('.bsbi-validation-banner');
                if (banner) { banner.remove(); }
            } else {
                showBanner(remaining);
            }
        }
        updateSections();
    });

    // Initialise conditional section state on load
    updateSections();
}());
</script>
