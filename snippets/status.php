<?php /** @noinspection PhpUnhandledExceptionInspection */


declare(strict_types=1);

use BSBI\WebBase\models\BaseWebPage;

if (!isset($currentPage)) :
    throw new Exception('$currentPage not provided');
endif;

/** @var BaseWebPage $currentPage */

if ($currentPage->hasFriendlyMessages()) :
    $messageStatus = $currentPage->getStatus() ? 'success' : 'danger';?>
<div class="alert alert-<?=$messageStatus?> alert-dismissible fade show m-0" role="alert" id="statusAlert">
    <h3>
        <?php foreach ($currentPage->getFriendlyMessages() as $message) : ?>
            <?= esc($message) ?> <br>
        <?php endforeach ?>
    </h3>
</div>

<?php if (!($currentPage->isCriticalError())) :
    return;
endif;
if (!$currentPage->getStatus()) : ?>
    <div class="container mt-2">
        <a href="/" class="btn btn-outline-primary">Return to home page</a>
    </div>
    <?php if ($currentPage->getCurrentUserRole() === 'admin') : ?>
    <div class="p-2">
        <h3>Error details</h3>
            <?php foreach ($currentPage->getErrorMessages() as $message) : ?>
        <p><?= esc($message) ?></p>
            <?php endforeach ?>
    </div>
    <?php else :
        if ($currentPage->isPasswordProtected()) :
            snippet('base/password');
        endif;
    endif;
    echo('</body></html>');
    die();
    endif;?>
<?php endif ?>


