<?php
/**
 * Responsive image snippet with AVIF/WebP support and Core Web Vitals optimizations
 *
 * @var Image $image The Image object to render
 * @var bool $showDimensions Whether to include width/height attributes (recommended for CLS)
 *
 * Alt and title come from editor-entered file metadata, so both are escaped for the
 * attribute; the title is the caption with its Kirbytext markup removed first.
 */

declare(strict_types=1);

use BSBI\WebBase\helpers\KirbyRetrievalException;
use BSBI\WebBase\models\Image;
use Kirby\Toolkit\Html;

if (!isset($image)) :
    return;
endif;

if (!$image instanceof Image) :
  /** @noinspection PhpUnhandledExceptionInspection */
  throw new KirbyRetrievalException("$image not an instance of Image");
endif;

// Default to showing dimensions to prevent Cumulative Layout Shift (CLS)
if (!isset($showDimensions)) :
    $showDimensions = true;
endif;

?>

<figure>
    <picture>
<?php if ($image->hasAvifSrcset()) : ?>
        <source type="image/avif" srcset="<?= $image->getAvifSrcset() ?>"<?php if ($image->hasSizes()) : ?> sizes="<?= $image->getSizes() ?>"<?php endif ?>>
<?php endif ?>
        <source type="image/webp" srcset="<?= $image->getWebpSrcset() ?>"<?php if ($image->hasSizes()) : ?> sizes="<?= $image->getSizes() ?>"<?php endif ?>>
        <img
<?php if ($image->hasClass()) : ?>
            class="<?= $image->getClass() ?>"
<?php endif ?>
<?php if ($image->hasStyle()) : ?>
            style="<?= $image->getStyle() ?>"
<?php endif ?>
            alt="<?= esc($image->getAlt()) ?>"
            src="<?= $image->getSrc() ?>"
            srcset="<?= $image->getSrcset() ?>"
<?php if ($showDimensions && $image->getWidth() > 0) : ?>
            width="<?= $image->getWidth() ?>"
<?php endif ?>
<?php if ($showDimensions && $image->getHeight() > 0) : ?>
            height="<?= $image->getHeight() ?>"
<?php endif ?>
<?php if ($image->hasSizes()) : ?>
            sizes="<?= $image->getSizes() ?>"
<?php endif ?>
            loading="<?= $image->getLoading() ?>"
            decoding="<?= $image->getDecoding() ?>"
<?php if ($image->hasFetchPriority()) : ?>
            fetchpriority="<?= $image->getFetchPriority() ?>"
<?php endif ?>
<?php if ($image->hasCaption()) : ?>
            title="<?= esc(Html::decode($image->getCaption())) ?>"
<?php endif ?>
        >
    </picture>
</figure>
