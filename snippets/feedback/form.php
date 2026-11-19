<?php /** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection PhpUnhandledExceptionInspection */

declare(strict_types=1);

if (!isset($currentPage)) :
    throw new Exception('$currentPage not provided');
endif;

if (!(method_exists($currentPage, 'getFeedbackForm'))) :
    throw new Exception('$currentPage does not have getFeedbackForm method');
endif;

$feedbackForm = $currentPage->getFeedbackForm();
if ($feedbackForm->hasFriendlyMessages()) :
    $alertType = $feedbackForm->getStatus() ? 'success' : 'danger';?>
    <div class="alert alert-<?=$alertType?> alert-dismissible fade show" role="alert" id="statusAlert">
        <p>
            <?php foreach ($feedbackForm->getFriendlyMessages() as $message) : ?>
                <?= esc($message) ?> <br>
            <?php endforeach ?>
        </p>
    </div>
<?php endif ?>

<form method="post">
  <div class="mb-3">
      <?php snippet('form/textbox', [
          'id' => 'name',
          'name' => 'name',
          'label' => 'Your name:',
          'value' => $feedbackForm->getNameValue(),
          'alert' => $feedbackForm->getNameAlert(),
          'required' => true]) ?>
  </div>
  <div class="mb-3">
      <?php snippet('form/textbox', [
          'id' => 'email',
          'name' => 'email',
          'type' => 'email',
          'label' => 'Your email:',
          'value' => $feedbackForm->getEmailValue(),
          'alert' => $feedbackForm->getEmailAlert(),
          'required' => true]) ?>
  </div>
  <div class="mb-3">
      <?php snippet('form/textarea', [
          'id' => 'feedback',
          'name' => 'feedback',
          'label' => 'Your feedback:',
          'value' => $feedbackForm->getFeedbackValue(),
          'alert' => $feedbackForm->getFeedbackAlert(),
          'required' => true]) ?>
  <?php snippet('form/turnstile', ['siteKey' => $feedbackForm->getTurnstileSiteKey()]) ?>
    <input type="hidden" id="page" name="page" value="<?=get('page')?>">
    <input type="submit" name="submit" value="Send us your feedback" class="btn btn-outline-success">
</form>