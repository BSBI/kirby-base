<?php /** @noinspection PhpUndefinedMethodInspection */

declare(strict_types=1);

/**
 * @var App $kirby
 * @var Site $site
 * @var Block $block
 */

use Kirby\Cms\App;
use Kirby\Cms\Block;
use Kirby\Cms\Site;


?>
<div class="list-group m-1 p-2">
<?php if ($file = \BSBI\WebBase\helpers\UuidResolver::instance()->fileFromField($block->file())) :
    $fileUrl = $file->permanentUrl()->isNotEmpty() ? kirby()->url() . '/files/' . $file->permanentUrl()->value() : $file->url();?>
    <a class="list-group-item" href="<?= $fileUrl?>" target="_blank">
        <img src="/assets/images/icons/file-text.svg" alt="File icon">
        <?=$block->label() != "" ? $block->label() : $file->filename()?>: VIEW</a>
<?php else : ?>
    <p>No file</p>
<?php endif?>
</div>
