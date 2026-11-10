<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

use DateTimeImmutable;
use Kirby\Cms\Page;
use Kirby\Content\Field;

/**
 * Which roles a page is restricted to today.
 *
 * A page restricted with `requiredRoles` may also carry `requiredRolesUntil`:
 * restricted up to and including that day, open to everyone from the next
 * (bsbi-web#812). Worked out when the page is read, never by a scheduled job,
 * so nothing has to happen on the day. An empty date, or anything that isn't a
 * Y-m-d date, keeps the restriction (fails closed).
 *
 * Restrictions are inherited: the nearest page up the tree with a restriction
 * still in force applies, so a page whose own restriction has lapsed falls
 * back to a restricted ancestor. The access check (UserService) and the page
 * cache rule (KirbyBaseHelper::isPageCacheable) both ask this class, so they
 * can never disagree about a page.
 */
final class RoleRestriction
{
    /**
     * @param list<string> $roles The page's required roles
     * @param string $until Its `requiredRolesUntil` value ('' for none)
     * @param DateTimeImmutable $today The day to judge by
     * @return list<string> The roles, or [] once the end date has passed
     */
    public static function activeRoles(array $roles, string $until, DateTimeImmutable $today): array
    {
        if ($roles === [] || trim($until) === '') {
            return $roles;
        }
        // Only a Y-m-d date (what the Panel's date field stores, optionally with
        // a time) ends a restriction. Anything else, including relative words
        // such as "yesterday" that would move with the clock, keeps it.
        $date = substr(trim($until), 0, 10);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            return $roles;
        }
        return $today->format('Y-m-d') <= $date ? $roles : [];
    }

    /**
     * The roles restricting this page today: its own if still in force, else
     * the nearest ancestor's still in force, else [].
     *
     * @param Page $page
     * @param DateTimeImmutable|null $today Defaults to the current day
     * @return list<string>
     */
    public static function forPage(Page $page, ?DateTimeImmutable $today = null): array
    {
        $today ??= new DateTimeImmutable('today');
        for ($current = $page; $current !== null; $current = $current->parent()) {
            $roles = self::activeRoles(self::roles($current), self::until($current), $today);
            if ($roles !== []) {
                return $roles;
            }
        }
        return [];
    }

    /**
     * @param Page $page
     * @return list<string>
     */
    private static function roles(Page $page): array
    {
        $field = $page->content()->get('requiredRoles');
        if (!$field instanceof Field) {
            return [];
        }
        return array_values(array_filter(
            array_map('trim', explode(',', self::text($field))),
            static fn (string $role): bool => $role !== ''
        ));
    }

    /**
     * @param Page $page
     * @return string
     */
    private static function until(Page $page): string
    {
        $field = $page->content()->get('requiredRolesUntil');
        return $field instanceof Field ? self::text($field) : '';
    }

    /**
     * @param Field $field
     * @return string The field's value as text ('' if it isn't scalar)
     */
    private static function text(Field $field): string
    {
        $value = $field->value();
        return is_scalar($value) ? (string)$value : '';
    }
}
