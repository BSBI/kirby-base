<?php

declare(strict_types=1);

use BSBI\WebBase\helpers\ListBlockStyle;
use Kirby\Cms\Block;

/**
 * List block (bsbi-web#786): Kirby's own list snippet, plus the block's
 * "List style" (letters or roman numerals) as the type of the outermost <ol>.
 * Only ListBlockStyle's allow-listed values reach the attribute.
 *
 * @var Block $block
 */

$style = $block->content()->get('listStyle');
echo ListBlockStyle::apply(
    $block->text()->toString(),
    $style instanceof \Kirby\Content\Field ? $style->toString() : ''
);
