<?php /** @noinspection PhpUndefinedMethodInspection */

declare(strict_types=1);

use BSBI\WebBase\helpers\UuidResolver;
use Kirby\Cms\Block;
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
 * @var Block $block
 */

$cards = $block->cards()->toStructure();
if ($cards->isEmpty()) {
    return;
}

$columns = (string) $block->columns()->or('3')->value();
$colClass = match ($columns) {
    '2', '2 columns' => 'col-12 col-md-6',
    '4', '4 columns' => 'col-12 col-sm-6 col-lg-3',
    default => 'col-12 col-sm-6 col-lg-4',
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
        $image = UuidResolver::instance()->fileFromField($card->image());
        // A linked card without a title still needs a link with a name: fall back to the URL.
        $title = $card->title()->isNotEmpty() ? $card->title()->value() : $url;
        ?>
        <div class="<?= $colClass ?> mb-4 d-flex">
            <div class="card border-0 flex-fill">
                <?php if ($image): ?>
                    <img src="<?= esc($image->url()) ?>" class="card-img-top" alt="<?= $image->alt()->esc() ?>">
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
