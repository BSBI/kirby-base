<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

use Kirby\Cms\Site;

/**
 * The single source for the public site name and the signals built from it
 * (bsbi-web#635).
 *
 * Google derives a site's display name from, in order: WebSite JSON-LD on the
 * homepage, og:site_name, the homepage <title>, homepage headings, then the
 * bare domain. This service feeds the first three from one value so they
 * cannot drift: the `siteName` site field when set, falling back to Kirby's
 * site title — so a consuming site that has not set the field keeps exactly
 * its current behaviour, and the Kirby site title is freed to be whatever
 * reads best above the Panel.
 */
final readonly class SiteIdentity
{
    /**
     * @param KirbyFieldReader $fieldReader Reads the site fields
     * @param Site $site The Kirby site (fallback title and canonical URL)
     */
    public function __construct(
        private KirbyFieldReader $fieldReader,
        private Site $site,
    ) {
    }

    /**
     * The canonical public site name.
     *
     * @return string The `siteName` site field, or the Kirby site title when unset/blank
     */
    public function getSiteName(): string
    {
        $siteName = trim($this->fieldReader->getSiteFieldAsString('siteName'));
        return $siteName !== '' ? $siteName : $this->site->title()->toString();
    }

    /**
     * The short site name (feeds JSON-LD `alternateName`).
     *
     * @return string The `siteShortName` site field, or '' when unset
     */
    public function getSiteShortName(): string
    {
        return trim($this->fieldReader->getSiteFieldAsString('siteShortName'));
    }

    /**
     * Builds a page's html <title>.
     *
     * The homepage REPLACES the title with the bare site name rather than
     * suffixing it — suffixing would render "Name - Name", and the bare
     * organisation name is the strongest homepage title signal.
     *
     * @param string $pageTitle The page's own title
     * @param bool $isHomePage Whether this is the site's homepage
     * @return string The full html title
     */
    public function buildHtmlTitle(string $pageTitle, bool $isHomePage): string
    {
        if ($isHomePage) {
            return $this->getSiteName();
        }
        return $pageTitle . ' - ' . $this->getSiteName();
    }

    /**
     * The WebSite structured-data array for the homepage.
     *
     * `alternateName` is included only when a short name is set — offering
     * Google a short form beats having it pick one, but an empty value would
     * be noise.
     *
     * @return array<string, string> JSON-LD properties, ready to json_encode
     */
    public function getWebsiteJsonLd(): array
    {
        $jsonLd = [
            '@context' => 'https://schema.org',
            '@type'    => 'WebSite',
            'name'     => $this->getSiteName(),
        ];
        $shortName = $this->getSiteShortName();
        if ($shortName !== '') {
            $jsonLd['alternateName'] = $shortName;
        }
        $jsonLd['url'] = $this->site->url() . '/';
        return $jsonLd;
    }

    /**
     * Encodes a JSON-LD array for embedding in a <script> block.
     *
     * JSON_HEX_TAG escapes `<`/`>` (and JSON_HEX_AMP `&`) as \uXXXX, so a
     * field value containing "</script>" cannot break out of the script
     * element — json_encode alone would emit it verbatim. Kept here rather
     * than in the snippet so the guarantee is unit-tested.
     *
     * @param array<string, string> $jsonLd The JSON-LD properties
     * @return string Script-safe JSON
     */
    public static function encodeJsonLd(array $jsonLd): string
    {
        return (string) json_encode(
            $jsonLd,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
        );
    }
}
