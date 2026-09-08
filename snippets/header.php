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
    <meta name="description" content="<?= esc($currentPage->getDescription(), 'attr') ?>">
    <?php endif ?>
    <meta name="author" content="<?= esc($currentPage->getAuthors(), 'attr') ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta property="og:title" content="<?= esc($currentPage->getOpenGraphTitle(), 'attr') ?>" />
    <meta property="og:description" content="<?= esc($currentPage->getOpenGraphDescription(), 'attr') ?>" />
    <meta property="og:image" content="<?= esc($currentPage->getOpenGraphImage(), 'attr') ?>" />
    <meta property="og:url" content="<?= esc($currentPage->getUrl(), 'attr') ?>" />
    <meta property="og:type" content="website" />
    <meta property="og:site_name" content="<?= esc($currentPage->getSiteName(), 'attr') ?>" />
    <?php if ($currentPage->isHomePage()) : ?>
    <?php snippet('base/website-jsonld', ['currentPage' => $currentPage]) ?>
    <?php endif ?>
    <?php snippet('base/favicon') ?>
    <?php snippet('base/robots-meta-tag') ?>
    <meta name="kirby-page-id" content="<?= esc($currentPage->getPageId(), 'attr') ?>">
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


