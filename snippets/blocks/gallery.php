<?php /** @noinspection PhpUndefinedMethodInspection */

declare(strict_types=1);

/**
 * @var App $kirby
 * @var Site $site
 * @var Page $page
 * @var Block $block
 */

use Kirby\Cms\Html;
use Kirby\Cms\Page;
use Kirby\Cms\App;
use Kirby\Cms\Block;
use Kirby\Cms\Site;
use Kirby\Toolkit\Str;
use Kirby\Toolkit\Collection;

$overallCaption = $block->caption();
$crop    = $block->crop()->isTrue();
$ratio   = $block->ratio()->or('auto')->value();
$fullWidth = $block->fullWidth()->toBool();
$figCaptionClass = '';

$location = $block->location()->value() ?: 'kirby';
if ($location === 'bank') {
    $bankIds = $block->bank()->value() ?: [];
    $resolvedFiles = \BSBI\WebBase\helpers\UuidResolver::instance()->files($bankIds)->values();
    $allImages = new Collection($resolvedFiles);
} else {
    $allImages = \BSBI\WebBase\helpers\UuidResolver::instance()->filesFromField($block->images());
}
$hideSeeAllButton = $block->hideSeeAllButton()->toBool();

/** @var Collection<Kirby\Cms\File> $visibleImages */
$visibleImages = $allImages;
/** @var Collection<Kirby\Cms\File>|null $hiddenImages */
$hiddenImages = null;

// Logic to split images if 'showSeeAllButton' is true and there are more than 2 images
if (!$hideSeeAllButton && $allImages->count() > 2) {
    $visibleImages = $allImages->slice(0, 2);
    $hiddenImages  = $allImages->slice(2);
}

// Function to render a single image figure (to avoid repetition)
$renderImage = function (Kirby\Cms\File $image, string $ratio, bool $crop, Block $block, string $imgClass, string $figCaptionClass) {
    $alt     = $image->alt();
    $caption = $image->caption() ?? '';


    $title = $image->caption()->isNotEmpty()  ? $image->caption() :  $image->alt();
    if ($image->photographer()->isNotEmpty()) {
        $title .= ' Photographer: '.$image->photographer()->value();
    }
    if ($image->license()->isNotEmpty()) {
        $title .= ' License: '.$image->license()->value();
    }
    $link    = $image->link();

    if ($block->fixedWidth()->isNotEmpty()) {
        $image = $image->resize((int)$block->fixedWidth()->value());
    }
    $src = $image->url();

    if ($src) : ?>
        <figure<?= Html::attr(['data-ratio' => $ratio, 'data-crop' => $crop], null, ' ') ?>>
            <?php if ($link->isNotEmpty()): ?>
                <a href="<?= Str::esc($link->toUrl()) ?>">
                    <img class="<?= $imgClass ?>" src="<?= $src ?>" alt="<?= $alt->esc() ?>" title="<?=$title?>">
                </a>
            <?php else: ?>
                <img class="<?= $imgClass ?>" src="<?= $src ?>" alt="<?= $alt->esc() ?>" title="<?=$title?>">
            <?php endif ?>

            <?php if ($caption->isNotEmpty()): ?>
                <figcaption class="<?= $figCaptionClass ?>">
                    <?= $caption->kt() ?>
                </figcaption>
            <?php endif ?>
        </figure>
    <?php endif;
};

$imgClass="figure-img img-fluid";
$figCaptionClass="figure-caption";
?>

<?php snippet('base/full-width-block-starts', ['fullWidth' => $fullWidth]) ?>
<div class="container">
    <div class="row row-cols-1 row-cols-sm-2">
        <?php
        // --- RENDER VISIBLE IMAGES (Max 2, or all if no split) ---
        foreach ($visibleImages as $image) :
            ?>
            <div class="col">
                <?php $renderImage($image, $ratio, $crop, $block, $imgClass, $figCaptionClass); ?>
            </div>
        <?php endforeach ?>
    </div>

    <?php
    // --- RENDER HIDDEN IMAGES BEHIND DETAILS/SUMMARY ---
    if ($hiddenImages && $hiddenImages->count() > 0) :
        ?>
        <details class="as-accordion mt-2 p-0">
            <summary class="btn btn-outline-primary mb-2">
                See all images
            </summary>
            <div class="row row-cols-2 pt-6">
                <?php foreach ($hiddenImages as $image) : ?>
                    <div class="col">
                        <?php $renderImage($image, $ratio, $crop, $block, $imgClass, $figCaptionClass); ?>
                    </div>
                <?php endforeach ?>
            </div>
        </details>
    <?php endif; ?>

</div>
<?php if ($overallCaption->isNotEmpty()): ?>
    <p class="<?=$figCaptionClass?> mt-3">
        <?= $overallCaption ?>
    </p>
<?php endif;

snippet('base/full-width-block-ends', ['fullWidth' => $fullWidth]);
?>
