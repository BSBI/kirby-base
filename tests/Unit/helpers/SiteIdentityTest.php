<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\KirbyFieldReader;
use BSBI\WebBase\helpers\SiteIdentity;
use Kirby\Cms\App;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SiteIdentity (bsbi-web#635).
 *
 * The service is the single source for the public site name: the `siteName`
 * site field when set, falling back to Kirby's site title so a consuming site
 * that has not set the field keeps exactly its current behaviour. It also
 * builds every page's html title (homepage REPLACES rather than suffixes —
 * the "Name - Name" trap) and the WebSite JSON-LD array for the homepage.
 *
 * One App boots in setUpBeforeClass (constructing an App registers global
 * handlers, which PHPUnit flags as risky inside a test); each scenario then
 * clones the Site with its own virtual content, which touches no global state.
 */
final class SiteIdentityTest extends TestCase
{
    private static App $kirby;

    public static function setUpBeforeClass(): void
    {
        self::$kirby = \BSBI\WebBase\Testing\KirbyTestEnvironment::boot('kirby-base-site-identity-' . uniqid());
    }

    public function testSiteNameReadsTheField(): void
    {
        $identity = $this->identity(['title' => 'BSBI Website', 'siteName' => 'Botanical Society of Britain & Ireland']);
        $this->assertSame('Botanical Society of Britain & Ireland', $identity->getSiteName());
    }

    public function testSiteNameFallsBackToSiteTitle(): void
    {
        $identity = $this->identity(['title' => 'BSBI Website']);
        $this->assertSame('BSBI Website', $identity->getSiteName());
    }

    public function testSiteNameFallsBackWhenFieldIsWhitespace(): void
    {
        $identity = $this->identity(['title' => 'BSBI Website', 'siteName' => '  ']);
        $this->assertSame('BSBI Website', $identity->getSiteName());
    }

    public function testShortNameReadsFieldAndDefaultsEmpty(): void
    {
        $this->assertSame('BSBI', $this->identity(['title' => 'T', 'siteShortName' => 'BSBI'])->getSiteShortName());
        $this->assertSame('', $this->identity(['title' => 'T'])->getSiteShortName());
    }

    public function testHtmlTitleSuffixesOrdinaryPages(): void
    {
        $identity = $this->identity(['title' => 'BSBI Website', 'siteName' => 'Botanical Society of Britain & Ireland']);
        $this->assertSame(
            'Orchids - Botanical Society of Britain & Ireland',
            $identity->buildHtmlTitle('Orchids', false)
        );
    }

    public function testHomepageTitleIsTheBareSiteNameNotDuplicated(): void
    {
        $identity = $this->identity(['title' => 'BSBI Website', 'siteName' => 'Botanical Society of Britain & Ireland']);
        $this->assertSame(
            'Botanical Society of Britain & Ireland',
            $identity->buildHtmlTitle('Home', true)
        );
    }

    public function testHtmlTitleFallsBackToSiteTitleWhenFieldUnset(): void
    {
        // The regression guard: a consuming site without the field keeps
        // exactly its current titles.
        $identity = $this->identity(['title' => 'BSBI Website']);
        $this->assertSame('Orchids - BSBI Website', $identity->buildHtmlTitle('Orchids', false));
    }

    public function testWebsiteJsonLdShape(): void
    {
        $identity = $this->identity([
            'title'         => 'BSBI Website',
            'siteName'      => 'Botanical Society of Britain & Ireland',
            'siteShortName' => 'BSBI',
        ]);

        $jsonLd = $identity->getWebsiteJsonLd();

        $this->assertSame('https://schema.org', $jsonLd['@context']);
        $this->assertSame('WebSite', $jsonLd['@type']);
        $this->assertSame('Botanical Society of Britain & Ireland', $jsonLd['name']);
        $this->assertSame('BSBI', $jsonLd['alternateName']);
        $this->assertArrayHasKey('url', $jsonLd);
        $this->assertNotSame('', $jsonLd['url']);
    }

    public function testEncodedJsonLdCannotBreakOutOfAScriptElement(): void
    {
        // A malicious/mistaken Panel value must not terminate the JSON-LD
        // <script> block: encodeJsonLd hex-escapes <, > and &.
        $encoded = SiteIdentity::encodeJsonLd([
            'name' => '</script><script>alert(1)</script> & Co',
        ]);

        $this->assertStringNotContainsString('<', $encoded);
        $this->assertStringNotContainsString('>', $encoded);
        $this->assertStringNotContainsString('&', $encoded);
        $decoded = json_decode($encoded, true);
        $this->assertSame('</script><script>alert(1)</script> & Co', $decoded['name'] ?? null, 'escaping must round-trip losslessly');
    }

    public function testWebsiteJsonLdOmitsAlternateNameWhenNoShortName(): void
    {
        $jsonLd = $this->identity(['title' => 'T', 'siteName' => 'Some Society'])->getWebsiteJsonLd();
        $this->assertArrayNotHasKey('alternateName', $jsonLd);
    }

    /**
     * Builds a SiteIdentity over an App with the given virtual site content.
     *
     * @param array<string, string> $siteContent Site content fields (title, siteName, …)
     */
    private function identity(array $siteContent): SiteIdentity
    {
        $site = self::$kirby->site()->clone(['content' => $siteContent]);

        return new SiteIdentity(
            new KirbyFieldReader(self::$kirby, $site),
            $site
        );
    }
}
