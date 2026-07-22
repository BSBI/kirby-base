<?php

declare(strict_types=1);

use BSBI\WebBase\helpers\HtmlLangResolver;

/**
 * Emits the `lang` and `dir` attributes for the current request language, e.g.
 * `lang="cy" dir="ltr"`. Use inside the opening `<html>` tag:
 *
 *   <html <?php snippet('html-lang') ?>>
 *
 * Keeps front-end markup free of hardcoded `lang="en"` (WCAG 3.1.1). See #612.
 */
echo (new HtmlLangResolver(kirby()))->attributes();
