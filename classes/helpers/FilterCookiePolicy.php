<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

/**
 * Namespacing and cache-detection rules for per-user filter cookies.
 *
 * Listing pages (news, events, blog, referees, species …) persist the visitor's
 * filter selections in cookies so they survive pagination and page reloads.
 * Those pages are otherwise publicly cacheable, but Kirby's page cache is keyed
 * on the URL only — it never varies by cookie. Caching an actively-filtered
 * render would therefore serve one visitor's filters to everyone else and would
 * survive that visitor clearing the filter (issue #603).
 *
 * To let the cache layer detect a filtered request cheaply and without an
 * enumerated list of every (dynamically-named) filter cookie, every filter
 * cookie name is namespaced with a reserved {@see self::PREFIX} ('flt_'). A
 * request that carries any non-empty flt_ cookie is user-specific and must be
 * rendered fresh and marked private rather than cached.
 *
 * THE CONTRACT: all filter cookies MUST be written and read through
 * KirbyBaseHelper::setFilterCookie() / getFilterCookieAsString() /
 * deleteFilterCookie(), which apply this prefix. A filter cookie set with the
 * raw setCookie() would be invisible here and reintroduce the caching bug.
 *
 * @package BSBI\WebBase
 */
final readonly class FilterCookiePolicy
{
    /** Reserved name prefix marking a cookie as a per-user filter selection */
    public const string PREFIX = 'flt_';

    /**
     * @param string $prefix The filter-cookie name prefix (defaults to {@see self::PREFIX})
     */
    public function __construct(private string $prefix = self::PREFIX)
    {
    }

    /**
     * Maps a caller-facing filter key to its stored (prefixed) cookie name.
     *
     * @param string $key The filter key, e.g. 'newsExternalKeywords'
     * @return string The prefixed cookie name, e.g. 'flt_newsExternalKeywords'
     */
    public function prefixedName(string $key): string
    {
        return $this->prefix . $key;
    }

    /**
     * @param string $cookieName A raw cookie name from the request
     * @return bool True when the cookie is a namespaced filter cookie
     */
    public function isFilterCookie(string $cookieName): bool
    {
        return str_starts_with($cookieName, $this->prefix);
    }

    /**
     * Whether the request carries an active filter — i.e. any filter cookie
     * with a non-empty value. Empty filter cookies (a selection the visitor has
     * explicitly cleared) leave the page identical to a fresh visitor's, so they
     * do not count and the page stays cacheable.
     *
     * @param array<string, mixed> $cookies The request cookies (typically $_COOKIE)
     * @return bool True when at least one filter cookie holds a non-empty value
     */
    public function requestCarriesActiveFilter(array $cookies): bool
    {
        foreach ($cookies as $name => $value) {
            if ($this->isFilterCookie($name) && $value !== '' && $value !== null) {
                return true;
            }
        }
        return false;
    }
}
