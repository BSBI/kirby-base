<?php

declare(strict_types=1);

use BSBI\WebBase\forms\ResolvedFormField;

/** @var ResolvedFormField $field */

?>
<?php // A fieldset, so assistive technology announces the question with each option (WCAG 1.3.1). ?>
<fieldset<?php if ($field->help !== '') : ?> aria-describedby="<?= $field->name ?>-help"<?php endif ?>>
<legend class="fs-6 fw-bold mb-3"><?= $field->label ?><?php if ($field->required) : ?> <span class="visually-hidden">(required)</span><span class="text-danger" aria-hidden="true">*</span><?php endif ?></legend>
<?php if ($field->help !== '') : ?><div class="form-text mb-2" id="<?= $field->name ?>-help"><?= $field->help ?></div><?php endif; ?>
<?php foreach ($field->options as $index => $option) :
    snippet('form/checkbox', [
        'label'           => $option,
        'id'              => $field->name . '_' . $index,
        'name'            => $field->name . '[]',
        'checkboxOrRadio' => 'checkbox',
        'value'           => $option,
        'labelLayout'     => 'small',
        'required'        => $field->required && $index === 0,
    ]);
endforeach; ?>
</fieldset>
