<?php

declare(strict_types=1);

use Kirby\Cms\Block;

/** @var Block $block */

$rawOptions = array_values(array_filter(array_map('trim', explode("\n", (string) $block->options()->value()))));
$options    = array_map(static fn(string $o): array => ['value' => $o, 'display' => $o], $rawOptions);

snippet('form/select', [
    'id'       => \BSBI\WebBase\forms\panel\PanelFieldReader::keyFor($block),
    'name'     => \BSBI\WebBase\forms\panel\PanelFieldReader::keyFor($block),
    'label'    => $block->label()->value(),
    'options'  => $options,
    'required' => $block->required()->isTrue(),
]);
