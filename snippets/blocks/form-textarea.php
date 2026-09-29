<?php

declare(strict_types=1);

use Kirby\Cms\Block;

/** @var Block $block */

snippet('form/textarea', [
    'id'       => \BSBI\WebBase\forms\panel\PanelFieldReader::keyFor($block),
    'name'     => \BSBI\WebBase\forms\panel\PanelFieldReader::keyFor($block),
    'label'    => $block->label()->value(),
    'required' => $block->required()->isTrue(),
]);
