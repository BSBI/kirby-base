<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

use Kirby\Cms\App;

/**
 * Resolves the site-relative paths the SQLite indexes are configured with
 * (e.g. '/logs/content-indexes/', the `search.databasePath` option).
 *
 * Anything under /logs/ resolves against Kirby's `logs` root rather than
 * `site/logs`. Kirby defaults that root to site/logs, so a normal site is
 * unchanged; a site served with its own logs root (the seeded browser-test
 * site) gets its own indexes instead of reading and writing another site's.
 */
final class SitePaths
{
    private const string LOGS_PREFIX = '/logs/';

    /**
     * @param App    $kirby    The Kirby instance whose roots apply.
     * @param string $sitePath A path relative to the site root, starting with '/'.
     * @return string The absolute path.
     */
    public static function resolve(App $kirby, string $sitePath): string
    {
        if (str_starts_with($sitePath, self::LOGS_PREFIX)) {
            return rtrim((string)$kirby->root('logs'), '/') . substr($sitePath, strlen('/logs'));
        }

        return $kirby->root('site') . $sitePath;
    }
}
