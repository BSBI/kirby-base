<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\FilterCookiePolicy;
use PHPUnit\Framework\TestCase;

/**
 * Tests for FilterCookiePolicy — the namespacing + cache-detection rules for
 * per-user filter cookies.
 *
 * Filter selections on listing pages are persisted in cookies whose names are
 * namespaced with a reserved prefix ('flt_'). The page cache is keyed on URL
 * only, so a request carrying an active (non-empty) filter cookie must be
 * treated as user-specific and rendered fresh rather than cached/served from
 * cache (issue #603).
 */
final class FilterCookiePolicyTest extends TestCase
{
    public function testPrefixesFilterCookieNames(): void
    {
        $policy = new FilterCookiePolicy();

        $this->assertSame('flt_newsExternalKeywords', $policy->prefixedName('newsExternalKeywords'));
        $this->assertSame('flt_', FilterCookiePolicy::PREFIX);
    }

    public function testIdentifiesFilterCookiesByPrefix(): void
    {
        $policy = new FilterCookiePolicy();

        $this->assertTrue($policy->isFilterCookie('flt_blogKeywords'));
        $this->assertFalse($policy->isFilterCookie('blogKeywords'));
        $this->assertFalse($policy->isFilterCookie('currency'));
        $this->assertFalse($policy->isFilterCookie('cookieConsent'));
    }

    public function testNoActiveFilterWhenNoFilterCookiesPresent(): void
    {
        $policy = new FilterCookiePolicy();

        $this->assertFalse($policy->requestCarriesActiveFilter([]));
        $this->assertFalse($policy->requestCarriesActiveFilter([
            'currency'      => 'gbp',
            'basket'        => 'abc-123',
            'cookieConsent' => 'accepted',
        ]));
    }

    public function testActiveFilterWhenAnyNonEmptyFilterCookiePresent(): void
    {
        $policy = new FilterCookiePolicy();

        $this->assertTrue($policy->requestCarriesActiveFilter([
            'currency'                => 'gbp',
            'flt_newsExternalKeywords' => 'Whitebeam',
        ]));
    }

    public function testEmptyFilterCookieIsNotAnActiveFilter(): void
    {
        // A filter the visitor has explicitly cleared persists as an empty
        // cookie; the page is still the same as a fresh visitor's, so it
        // remains cacheable.
        $policy = new FilterCookiePolicy();

        $this->assertFalse($policy->requestCarriesActiveFilter([
            'flt_newsExternalKeywords' => '',
        ]));
        $this->assertTrue($policy->requestCarriesActiveFilter([
            'flt_newsExternalKeywords' => '',
            'flt_newsExternalCountries' => 'england',
        ]));
    }

    public function testDynamicallyNamedNewsFilterCookiesAreCovered(): void
    {
        // News filter cookie names are built from a base key + suffix at runtime
        // (e.g. countryNewsKeywords + 'Countries'). The prefix contract means
        // every such cookie is detected without an enumerated list.
        $policy = new FilterCookiePolicy();

        $this->assertTrue($policy->requestCarriesActiveFilter([
            'flt_countryNewsKeywordsCountries' => 'wales',
        ]));
    }

    public function testCustomPrefix(): void
    {
        $policy = new FilterCookiePolicy('x_');

        $this->assertSame('x_family', $policy->prefixedName('family'));
        $this->assertTrue($policy->requestCarriesActiveFilter(['x_family' => 'Rosaceae']));
        $this->assertFalse($policy->requestCarriesActiveFilter(['flt_family' => 'Rosaceae']));
    }
}
