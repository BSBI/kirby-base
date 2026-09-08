<?php /** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection PhpUndefinedMethodInspection */

declare(strict_types=1);

use Kirby\Cms\Block;
use Kirby\Toolkit\Str;

/** @var Block $block */
$alt = $block->alt();
$caption = $block->caption();
$crop = $block->crop()->isTrue();
$link = $block->link();
$ratio = $block->ratio()->or('auto');
$src = null;

$sizes = "(min-width: 914px) calc(700px - 20px),
calc(100vw - 20px)";

$width = 600;
$imgClass = "img-fluid";
$figCaptionClass = "figure-caption";

$centreBlock = false;

if ($block->fixedWidth()->isNotEmpty()) :
    $width = (int) $block->fixedWidth()->value();
endif;

if ($block->isCentred()->isNotEmpty()) :
    if ($block->isCentred()->toBool()) :
        $centreBlock = true;
        $imgClass .= " mx-auto d-block";
        $figCaptionClass .= " text-center";
    endif;
endif;

// Determine image source (page, web, or bank)
$location= $block->location()->value();
$image = null;
$fullWidth =$block->fullWidth()->toBool();

snippet('base/full-width-block-starts', ['fullWidth' => $fullWidth]);


?>
<figure>
    <?php if ($link->isNotEmpty()) : ?>
    <a href="<?= Str::esc($link->toUrl()) ?>"
        <?php if ($caption->isNotEmpty()) : ?>
            aria-label="<?= $caption ?>"
        <?php endif ?>
    >
        <?php endif ?>

        <?php
        // Handle web location (external image)
        if ($block->location()->value() === 'web') :
            $src = $block->src()->esc();
            ?>
            <img class="<?= $imgClass ?>" src="<?= $src ?>" alt="<?= $alt->esc() ?>" loading="lazy" decoding="async">

        <?php
// Handle image bank source
        elseif ($location === 'bank' && $block->bank()->isNotEmpty()) :
            $imageBankId = $block->bank()->value()[0];

            if ($image = kirby()->file($imageBankId)) :
                ?>
                <picture>
                    <source type="image/webp" srcset="<?= $image->srcset('webp') ?>" sizes="<?= $sizes ?>">
                    <img class="<?= $imgClass ?>" alt="<?= $image->alt()->or($alt) ?>" src="<?= $image->resize($width)->url() ?>"
                         srcset="<?= $image->srcset() ?>" sizes="<?= $sizes ?>" width="<?= $image->resize($width)->width() ?>"
                         height="<?= $image->resize($width)->height() ?>" loading="lazy" decoding="async">
                </picture>
            <?php endif; ?>

        <?php
// Handle regular page image (original behavior)
        elseif ($image = \BSBI\WebBase\helpers\UuidResolver::instance()->fileFromField($block->image())) :
            ?>
            <picture>
                <source type="image/webp" srcset="<?= $image->srcset('webp') ?>" sizes="<?= $sizes ?>">
                <img class="<?= $imgClass ?>" alt="<?= $image->alt()->or($alt) ?>" src="<?= $image->resize($width)->url() ?>"
                     srcset="<?= $image->srcset() ?>" sizes="<?= $sizes ?>" width="<?= $image->resize($width)->width() ?>"
                     height="<?= $image->resize($width)->height() ?>" loading="lazy" decoding="async">
            </picture>
        <?php endif ?>

        <?php if ($link->isNotEmpty()) : ?>
    </a>
<?php endif ?>

    <?php if ($caption->isNotEmpty()) : ?>
        <figcaption class="<?= $figCaptionClass ?>">
            <?= $caption ?>
        </figcaption>
    <?php endif ?>
</figure>

<?php snippet('base/full-width-block-ends', ['fullWidth' => $fullWidth]);