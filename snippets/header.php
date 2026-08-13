<?php /** @noinspection PhpUnhandledExceptionInspection */

declare(strict_types=1);

use Kirby\Template\Slots;

if (!isset($currentPage)) :
    throw new Exception('$currentPage not provided');
endif;


/**
 * @var Slots $slots
 **/

?>
<!DOCTYPE html>
<html <?php snippet('html-lang') ?> <?php snippet('colour-mode/tag') ?>>
<head>
    <meta charset="utf-8">
    <title><?=strip_tags($currentPage->getHtmlTitle()) ?></title>
    <?php if ($currentPage->hasDescription()) : ?>
    <meta name="description" content="<?=$currentPage->getDescription()?>">
    <?php endif ?>
    <meta name="author" content="<?=$currentPage->getAuthors()?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta property="og:title" content="<?= $currentPage->getOpenGraphTitle() ?>" />
    <meta property="og:description" content="<?= $currentPage->getOpenGraphDescription() ?>" />
    <meta property="og:image" content="<?= $currentPage->getOpenGraphImage() ?>" />
    <meta property="og:url" content="<?= $currentPage->getUrl() ?>" />
    <meta property="og:type" content="website" />
    <meta property="og:site_name" content="<?= esc($currentPage->getSiteName(), 'attr') ?>" />
    <?php if ($currentPage->isHomePage()) : ?>
    <?php snippet('base/website-jsonld', ['currentPage' => $currentPage]) ?>
    <?php endif ?>
    <?php snippet('base/favicon') ?>
    <?php snippet('base/robots-meta-tag') ?>
    <meta name="kirby-page-id" content="<?= $currentPage->getPageId() ?>">
    <?php snippet('colour-mode/script') ?>
    <?php snippet('base/styles') ?>
<?php /** @noinspection PhpUndefinedMethodInspection */
if ($lowerHead = $slots->lowerHead()) : ?>
        <?= $lowerHead ?>
<?php endif ?>
</head>

<body>
<?php snippet('base/status') ?>
<?php snippet('skip-to-content') ?>


