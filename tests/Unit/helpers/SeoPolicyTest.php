<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\SeoPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SeoPolicy: the pure decisions behind sitemap inclusion and the
 * robots noindex meta tag.
 *
 * The flagship regression is nested pages (e.g. customer order pages living at
 * checkout/<uuid>) leaking into sitemap.xml because the exclusion list holds
 * template names but was previously matched against the page uri only.
 */
final class SeoPolicyTest extends TestCase
{
    /** @var list<string> */
    private const array IGNORE = ['login', 'order', 'checkout', 'basket_item'];

    public function testExcludesNestedPageByTemplateEvenWhenUriDiffers(): void
    {
        // An order page: uri is checkout/<uuid>, template is 'order'.
        self::assertTrue(
            SeoPolicy::isExcludedFromSitemap(
                'checkout/21e41bb1-a230-4a3d-9874-df05771e7303',
                'order',
                null,
                self::IGNORE
            )
        );
    }

    public function testExcludesTopLevelPageByUri(): void
    {
        self::assertTrue(
            SeoPolicy::isExcludedFromSitemap('login', 'login', null, self::IGNORE)
        );
    }

    public function testDoesNotExcludeOrdinaryPage(): void
    {
        self::assertFalse(
            SeoPolicy::isExcludedFromSitemap('about/history', 'default', null, self::IGNORE)
        );
    }

    public function testExcludesMembersAreaPages(): void
    {
        self::assertTrue(
            SeoPolicy::isExcludedFromSitemap('members/updates', 'default', null, self::IGNORE)
        );
    }

    public function testExcludesPageFlaggedNoindex(): void
    {
        self::assertTrue(
            SeoPolicy::isExcludedFromSitemap('news/some-post', 'default', 'noindex', self::IGNORE)
        );
    }

    public function testDoesNotExcludeWhenMetaRobotsIsIndex(): void
    {
        self::assertFalse(
            SeoPolicy::isExcludedFromSitemap('news/some-post', 'default', 'index', self::IGNORE)
        );
    }

    public function testIsNoindexTemplateMatchesListedTemplate(): void
    {
        self::assertTrue(SeoPolicy::isNoindexTemplate('order', ['order', 'checkout']));
    }

    public function testIsNoindexTemplateRejectsUnlistedTemplate(): void
    {
        self::assertFalse(SeoPolicy::isNoindexTemplate('default', ['order', 'checkout']));
    }

    public function testIsNoindexTemplateWithEmptyListIsFalse(): void
    {
        self::assertFalse(SeoPolicy::isNoindexTemplate('order', []));
    }
}
