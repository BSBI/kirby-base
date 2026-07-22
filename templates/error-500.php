<?php
/** @var string $userRole */
/** @var string $exception */
?>
<!DOCTYPE html>
<html <?php snippet('html-lang') ?>>
<head>
    <meta charset="utf-8">
    <title>Error</title>
    <?php snippet('base/styles') ?>
</head>
<body>
<div class="container mt-4">
    <p><a href="/">Home</a></p>
    <div class="alert alert-danger" role="alert">
        <h2>An unexpected error has occurred</h2>
        <?php if ($userRole === 'admin') : ?>
            <p><?= htmlspecialchars($exception) ?></p>
        <?php else : ?>
            <p>This error will be reported to the website administrators. We apologise for any inconvenience.</p>
        <?php endif ?>
        <a href="/" class="btn btn-outline-primary">Return to the website.</a>
    </div>
</div>
</body>
</html>
