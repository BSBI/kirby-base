<?php /** @noinspection PhpUndefinedMethodInspection */

declare(strict_types=1);

use BSBI\WebBase\helpers\UuidResolver;
use Kirby\Cms\Block;
use Kirby\Toolkit\Str;

/**
 * @var Block $block
 */

?>
<p>
    <a href="<?= Str::esc(UuidResolver::instance()->linkUrl($block->link())) ?>"
       class="btn btn-primary"
       <?= $block->openInNewTab()->isTrue() ? 'target="_blank" rel="noopener noreferrer"' : '' ?>>
    <?= $block->text() ?>
    </a>
</p>