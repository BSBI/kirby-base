<?php /** @noinspection PhpUnhandledExceptionInspection */

declare(strict_types=1);

use BSBI\WebBase\helpers\SeoPolicy;

if (!isset($currentPage)) :
    throw new Exception('$currentPage not provided');
endif;

$languages = $currentPage->getLanguages();

// Templates that must never be indexed (e.g. checkout/order pages holding
// customer data, auth pages). Configured per-site via the 'noindexTemplates' option.
$configuredTemplates = (array) kirby()->option('noindexTemplates', []);
$noindexTemplates = array_values(array_filter($configuredTemplates, is_string(...)));

$noindex = SeoPolicy::isNoindexTemplate($currentPage->getPageType(), $noindexTemplates)
    || ($languages->isEnabled()
        && !$languages->isUsingDefaultLanguage()
        && !$languages->isPageTranslatedInCurrentLanguage());

if ($noindex) : ?>
<meta name="robots" content="noindex, follow">
<?php endif ?>
