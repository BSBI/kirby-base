<?php

declare(strict_types=1);

$name         = $name ?? 'q1_satisfaction';
$label        = $label ?? 'How satisfied are you with the service?';
$scaleMin     = $scaleMin ?? 1;
$scaleMax     = $scaleMax ?? 5;
$leftLabel    = $leftLabel ?? 'Strongly disagree';
$middleLabel  = $middleLabel ?? 'Neutral';
$rightLabel   = $rightLabel ?? 'Strongly agree';
$required     = $required ?? false;
?>

<?php if (isset($label)) : ?>
<p><strong><?= $label ?><?php if (!empty($required)) : ?> <span class="visually-hidden">(required)</span><span class="text-danger" aria-hidden="true">*</span><?php endif ?></strong></p>
<?php endif ?>
<div class="card p-2 mb-2">
    <div class="d-flex flex-column gap-2 px-2">
        <div class="d-flex justify-content-between">
            <?php for ($i = $scaleMin; $i <= $scaleMax; $i++): ?>
                <?php $inputId = $name . '-' . $i; ?>
                <label for="<?= $inputId ?>" class="d-flex flex-column align-items-center gap-1">
                    <span class="form-label mb-0"><?= $i ?></span>
                    <input
                            class="form-check-input"
                            type="radio"
                            name="<?= $name ?>"
                            id="<?= $inputId ?>"
                            value="<?= $i ?>"
                            <?php if (isset($value) && $value === (string) $i) : ?>checked<?php endif; ?>
                            <?php if (!empty($required) && $i === $scaleMin) : ?>required<?php endif; ?>
                    >
                </label>
            <?php endfor; ?>
        </div>

        <div class="d-flex justify-content-between">
            <small class="text-danger fw-medium"><?= $leftLabel ?></small>
            <?php if ($middleLabel !== '') : ?>
                <small class="text-secondary fw-medium"><?= $middleLabel ?></small>
            <?php endif; ?>
            <small class="text-success fw-medium"><?= $rightLabel ?></small>
        </div>
    </div>
</div>
