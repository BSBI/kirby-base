<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\RoleRestriction;
use BSBI\WebBase\Testing\KirbyTestEnvironment;
use DateTimeImmutable;
use Kirby\Cms\Page;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A page restricted to some roles can carry an end date (`requiredRolesUntil`):
 * restricted up to and including that day, open to everyone from the next
 * (bsbi-web#812). The access check and the page-cache rule both read the
 * restriction through this service, so they can never disagree.
 */
final class RoleRestrictionTest extends TestCase
{
    private const string TODAY = '2026-11-10';

    public static function setUpBeforeClass(): void
    {
        KirbyTestEnvironment::boot('kirby-base-role-restriction');
    }

    private function today(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::TODAY);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function endDates(): array
    {
        return [
            'no end date'           => ['', true],
            'ends tomorrow'         => ['2026-11-11', true],
            'ends today, inclusive' => ['2026-11-10', true],
            'ended yesterday'       => ['2026-11-09', false],
            'ended long ago'        => ['2020-01-01', false],
            'with a time part'      => ['2026-11-09 23:59:00', false],
            'unreadable: stays restricted' => ['next tuesday-ish', true],
            // Only a real date counts: relative words would move with the clock.
            'a relative word: stays restricted' => ['yesterday', true],
            'another format: stays restricted'  => ['09/11/2026', true],
        ];
    }

    #[DataProvider('endDates')]
    public function testTheRestrictionLastsUntilTheEndOfItsLastDay(string $until, bool $restricted): void
    {
        $roles = RoleRestriction::activeRoles(['member', 'vice_county'], $until, $this->today());

        $this->assertSame($restricted ? ['member', 'vice_county'] : [], $roles);
    }

    public function testNoRolesMeansNoRestrictionWhateverTheDate(): void
    {
        $this->assertSame([], RoleRestriction::activeRoles([], '2030-01-01', $this->today()));
    }

    public function testAPageReadsItsOwnFields(): void
    {
        $page = Page::factory(['slug' => 'walk', 'content' => [
            'requiredRoles' => 'member, vice_county',
            'requiredRolesUntil' => '2026-11-20',
        ]]);

        $this->assertSame(['member', 'vice_county'], RoleRestriction::forPage($page, $this->today()));
    }

    public function testALapsedPageFallsBackToARestrictedAncestor(): void
    {
        $parent = Page::factory(['slug' => 'members', 'content' => ['requiredRoles' => 'member'], 'children' => [
            ['slug' => 'walk', 'content' => ['requiredRoles' => 'vice_county', 'requiredRolesUntil' => '2026-11-01']],
        ]]);
        $child = $parent->find('walk');
        $this->assertInstanceOf(Page::class, $child);

        $this->assertSame(['member'], RoleRestriction::forPage($child, $this->today()));
    }

    public function testAnOpenPageUnderAnOpenParentIsOpen(): void
    {
        $parent = Page::factory(['slug' => 'events', 'children' => [
            ['slug' => 'walk', 'content' => ['requiredRoles' => 'member', 'requiredRolesUntil' => '2026-11-01']],
        ]]);
        $child = $parent->find('walk');
        $this->assertInstanceOf(Page::class, $child);

        $this->assertSame([], RoleRestriction::forPage($child, $this->today()));
    }

    public function testTodayDefaultsToTheCurrentDay(): void
    {
        $page = Page::factory(['slug' => 'walk', 'content' => [
            'requiredRoles' => 'member',
            'requiredRolesUntil' => (new DateTimeImmutable('today'))->format('Y-m-d'),
        ]]);

        $this->assertSame(['member'], RoleRestriction::forPage($page));
    }
}
