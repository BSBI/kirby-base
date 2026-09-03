<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\KirbyInternalHelper;
use BSBI\WebBase\Testing\KirbyTestEnvironment;
use Kirby\Cms\Page;
use PHPUnit\Framework\TestCase;

/**
 * Drafts must never be cacheable (bsbi-web security review, draft-preview
 * work).
 *
 * isPageCacheable() feeds both the pages-cache 'ignore' closure and
 * setCacheHeaders(). A draft renders only for an authenticated editor
 * previewing it — but if that render is cached, the CDN (via
 * "Cache-Control: public") or the shared pages cache would serve the
 * unpublished content to anonymous visitors, bypassing the authorisation
 * gate entirely. Draft status must therefore veto cacheability before any
 * other consideration.
 */
final class PageCacheabilityDraftTest extends TestCase
{
    private static KirbyInternalHelper $helper;

    public static function setUpBeforeClass(): void
    {
        KirbyTestEnvironment::boot('kirby-base-page-cacheability-' . uniqid());
        self::$helper = new KirbyInternalHelper();
    }

    public function testADraftPageIsNeverCacheable(): void
    {
        $draft = Page::factory([
            'slug'    => 'unpublished-post',
            'isDraft' => true,
        ]);

        $this->assertFalse(self::$helper->isPageCacheable($draft));
    }

    public function testAnEquivalentPublishedPageIsCacheable(): void
    {
        $published = Page::factory([
            'slug' => 'published-post',
        ]);

        $this->assertTrue(self::$helper->isPageCacheable($published));
    }
}
