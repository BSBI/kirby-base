<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\KirbyInternalHelper;
use BSBI\WebBase\Testing\KirbyTestEnvironment;
use DateTimeImmutable;
use Kirby\Cms\Page;
use PHPUnit\Framework\TestCase;

/**
 * A restricted page is never page-cached; once its `requiredRolesUntil` has
 * passed it is public, so it may be (bsbi-web#812). The rule is the access
 * check's own (RoleRestriction), so a cached copy is only ever a public one.
 */
final class PageCacheabilityRestrictionTest extends TestCase
{
    private static KirbyInternalHelper $helper;

    public static function setUpBeforeClass(): void
    {
        KirbyTestEnvironment::boot('kirby-base-page-cacheability-restriction-' . uniqid());
        self::$helper = new KirbyInternalHelper();
    }

    private static function day(string $relative): string
    {
        return (new DateTimeImmutable('today'))->modify($relative)->format('Y-m-d');
    }

    /**
     * @param array<string, string> $content
     */
    private function page(array $content): Page
    {
        return Page::factory(['slug' => 'walk-' . uniqid(), 'content' => $content]);
    }

    public function testARestrictedPageWithNoEndDateIsNotCached(): void
    {
        $this->assertFalse(self::$helper->isPageCacheable($this->page(['requiredRoles' => 'member'])));
    }

    public function testARestrictionEndingTodayStillPreventsCaching(): void
    {
        $this->assertFalse(self::$helper->isPageCacheable($this->page([
            'requiredRoles' => 'member', 'requiredRolesUntil' => self::day('+0 days'),
        ])));
    }

    public function testALapsedRestrictionAllowsCaching(): void
    {
        $this->assertTrue(self::$helper->isPageCacheable($this->page([
            'requiredRoles' => 'member', 'requiredRolesUntil' => self::day('-1 day'),
        ])));
    }

    public function testALapsedPageUnderARestrictedParentIsNotCached(): void
    {
        $parent = Page::factory(['slug' => 'members-' . uniqid(), 'content' => ['requiredRoles' => 'member'], 'children' => [
            ['slug' => 'walk', 'content' => ['requiredRoles' => 'vice_county', 'requiredRolesUntil' => self::day('-1 day')]],
        ]]);
        $child = $parent->find('walk');
        $this->assertInstanceOf(Page::class, $child);

        $this->assertFalse(self::$helper->isPageCacheable($child));
    }
}
