<?php /** @noinspection PhpUndefinedMethodInspection */

declare(strict_types=1);

/**
 * @var \Kirby\Cms\App $kirby
 * @var \Kirby\Cms\Site $site
 * @var \Kirby\Cms\Block $block
 */
?>
<div class="list-group m-1 p-2">
<?php if ($file = \BSBI\WebBase\helpers\UuidResolver::instance()->fileFromField($block->file())) :
    // $file->url() is the permanent URL for File Archive files (file::url component).
    $fileUrl = $file->url();?>
    <a class="list-group-item" href="<?= $fileUrl?>" target="_blank">
        <img src="/assets/images/icons/file-text.svg" alt="File icon">
        <?=$block->label() != "" ? $block->label() : $file->filename()?>: VIEW</a>
<?php else : ?>
    <p>No file</p>
<?php endif?>
</div>
