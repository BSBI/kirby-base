<?php
/**
 * WebSite structured data (bsbi-web#635) — Google's strongest site-name
 * signal, read from the homepage only (the shared header includes this
 * snippet just for the homepage). The properties are built in SiteIdentity
 * and carried on the page model; encodeJsonLd() hex-escapes <, > and & so a
 * field value cannot break out of the script element — keep output going
 * through it.
 */

declare(strict_types=1);

use BSBI\WebBase\helpers\SiteIdentity;
use BSBI\WebBase\models\BaseWebPage;

if (!isset($currentPage) || !$currentPage instanceof BaseWebPage) :
    return;
endif;

$websiteJsonLd = $currentPage->getWebsiteJsonLd();
if ($websiteJsonLd === []) :
    return;
endif;
?>
    <script type="application/ld+json">
    <?= SiteIdentity::encodeJsonLd($websiteJsonLd) ?>
    </script>
