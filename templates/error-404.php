<?php
/**
 * Served by the global exception handler when Kirby's router found no route for the
 * request — usually a HEAD request to a Panel URL from a link scanner. There is nothing
 * to report, so no exception detail is shown to anyone.
 */
?>
<!DOCTYPE html>
<html <?php snippet('html-lang') ?>>
<head>
    <meta charset="utf-8">
    <title>Page not found</title>
    <?php snippet('base/styles') ?>
</head>
<body>
<div class="container mt-4">
    <p><a href="/">Home</a></p>
    <div class="alert alert-warning" role="alert">
        <h2>Page not found</h2>
        <p>There is no page at this address.</p>
        <a href="/" class="btn btn-outline-primary">Return to the website.</a>
    </div>
</div>
</body>
</html>
