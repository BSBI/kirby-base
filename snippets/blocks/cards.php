<?php /** @noinspection PhpUndefinedMethodInspection */

declare(strict_types=1);

use BSBI\WebBase\helpers\ImageService;
use BSBI\WebBase\helpers\KirbyRetrievalException;
use BSBI\WebBase\helpers\UuidResolver;
use BSBI\WebBase\models\ImageSizes;
use BSBI\WebBase\models\ImageType;
use Kirby\Cms\Block;
use Kirby\Cms\File;
use Kirby\Cms\StructureObject;

/**
 * Cards block: a titled grid of linked or unlinked cards.
 *
 * A linked card is a `<div class="card">` whose title carries the link as a
 * Bootstrap stretched link, so the whole card is clickable without any block
 * content sitting inside an anchor. The description is Kirbytext and may hold
 * its own links; wrapping the card in `<a>` would nest anchors, which browsers
 * repair by splitting the outer one (bsbi-web#727). Inline links in the body
 * need `position: relative; z-index: 2` from the site's CSS to sit above the
 * stretched link's overlay. The title link drops the underline on purpose: the
 * card as a whole is the affordance, shown by the site's hover/focus shadow on
 * `.card`, and the link keeps its colour and focus ring.
 *
 * Card images are served the way the sub-pages panel serves its images: through
 * ImageService (a server-side thumbnail with srcset and WebP/AVIF) and the shared
 * `base/image` snippet. With the block's `crop` toggle on (the default, and the
 * behaviour for blocks saved before the toggle existed) every image is a 400×300
 * crop with the 4:3 `panel` srcset, and the `img-fix-size` classes hold the box
 * shape while it loads, so the cards line up. With it off, the image keeps its
 * own ratio as a 400-wide thumbnail with the width-only `default` srcset — for
 * logos, which must not lose their edges. SVGs are served as they are (a vector
 * needs no thumbnail); if a thumbnail cannot be made the original file is served
 * rather than dropping the image.
 *
 * @var Block $block
 */

$cards = $block->cards()->toStructure();
if ($cards->isEmpty()) {
    return;
}

$columns = (string) $block->columns()->or('3')->value();
[$colClass, $sizes] = match ($columns) {
    '2', '2 columns' => ['col-12 col-md-6', ImageSizes::HALF_LARGE_SCREEN],
    '4', '4 columns' => ['col-12 col-sm-6 col-lg-3', ImageSizes::QUARTER_LARGE_SCREEN],
    default => ['col-12 col-sm-6 col-lg-4', ImageSizes::THIRD_LARGE_SCREEN],
};

// Crop is on unless the editor turned it off; a block saved before the toggle existed has no value.
$crop = $block->crop()->isEmpty() || $block->crop()->isTrue();
$imageClass = $crop ? 'card-img-top img-fix-size img-fix-size--four-three' : 'card-img-top';
$imageService = ImageService::instance();

/**
 * The card's image as an Image model, or null when the card has none.
 */
$cardImage = static function (?File $file) use ($imageService, $crop, $sizes, $imageClass) {
    if ($file === null) {
        return null;
    }
    if (strtolower($file->extension()) === 'svg') {
        return $imageService->getSvgImageFromFile($file, $imageClass);
    }
    try {
        return $crop
            ? $imageService->getImageFromFile($file, 400, 300, 80, ImageType::PANEL, '', $sizes, true, $imageClass)
            : $imageService->getImageFromFile($file, 400, null, 80, ImageType::DEFAULT, '', $sizes, false, $imageClass);
    } catch (KirbyRetrievalException) {
        return $imageService->getSvgImageFromFile($file, $imageClass);
    }
};

?>
<?php if ($block->title()->isNotEmpty()): ?>
    <h2 class="text-center mb-4"><?= $block->title()->esc() ?></h2>
<?php endif ?>
<div class="row align-items-stretch justify-content-center">
    <?php foreach ($cards as $card): ?>
        <?php
        /** @var StructureObject $card */
        $url = $card->url()->isNotEmpty() ? $card->url()->value() : null;
        $image = $cardImage(UuidResolver::instance()->fileFromField($card->image()));
        // A linked card without a title still needs a link with a name: fall back to the URL.
        $title = $card->title()->isNotEmpty() ? $card->title()->value() : $url;
        ?>
        <div class="<?= $colClass ?> mb-4 d-flex">
            <div class="card border-0 flex-fill">
                <?php if ($image?->isAvailable()): ?>
                    <?php snippet('base/image', ['image' => $image]) ?>
                <?php endif ?>
                <div class="card-body p-4">
                    <?php if ($url): ?>
                        <h3 class="card-title"><a href="<?= esc($url) ?>" class="stretched-link text-decoration-none"><?= esc($title) ?></a></h3>
                    <?php elseif ($title !== null): ?>
                        <h3 class="card-title"><?= esc($title) ?></h3>
                    <?php endif ?>
                    <?php if ($card->text()->isNotEmpty()): ?>
                        <?= $card->text()->kt() ?>
                    <?php endif ?>
                </div>
            </div>
        </div>
    <?php endforeach ?>
</div>
