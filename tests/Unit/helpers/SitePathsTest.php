<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\SitePaths;
use Kirby\Cms\App;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SitePaths (bsbi-web's seeded browser-test site).
 *
 * The SQLite indexes (content, search, file links, image bank) were built at
 * root('site') . '/logs/…'. A site served with its own logs root — the seeded
 * browser-test site — therefore read, and could write, the dev site's indexes.
 * Paths under /logs/ now resolve against root('logs'), which Kirby defaults to
 * site/logs, so a normal site is unchanged.
 */
final class SitePathsTest extends TestCase
{
    private static string $base;

    private static App $defaultLogs;

    private static App $movedLogs;

    /**
     * Both apps are booted here, not in a test: booting registers global
     * handlers, which PHPUnit flags as risky inside a test method.
     */
    public static function setUpBeforeClass(): void
    {
        self::$base = sys_get_temp_dir() . '/kirby-base-site-paths-' . uniqid();
        mkdir(self::$base . '/site', 0777, true);
        mkdir(self::$base . '/elsewhere-logs', 0777, true);

        self::$defaultLogs = new App(['roots' => ['index' => self::$base]]);
        self::$movedLogs = new App(['roots' => ['index' => self::$base, 'logs' => self::$base . '/elsewhere-logs']]);
    }

    public function testLogsPathsFollowTheLogsRoot(): void
    {
        $kirby = self::$movedLogs;

        $this->assertSame(
            self::$base . '/elsewhere-logs/content-indexes/',
            SitePaths::resolve($kirby, '/logs/content-indexes/')
        );
        $this->assertSame(
            self::$base . '/elsewhere-logs/search/search.sqlite',
            SitePaths::resolve($kirby, '/logs/search/search.sqlite')
        );
    }

    public function testDefaultLogsRootLeavesPathsWhereTheyWere(): void
    {
        $kirby = self::$defaultLogs;

        $this->assertSame(
            $kirby->root('site') . '/logs/content-indexes/',
            SitePaths::resolve($kirby, '/logs/content-indexes/')
        );
    }

    public function testOtherPathsStayUnderTheSiteRoot(): void
    {
        $kirby = self::$movedLogs;

        $this->assertSame($kirby->root('site') . '/data/index.sqlite', SitePaths::resolve($kirby, '/data/index.sqlite'));
        // Only the /logs/ segment moves: a folder merely starting with "logs" does not.
        $this->assertSame($kirby->root('site') . '/logsbook/x', SitePaths::resolve($kirby, '/logsbook/x'));
    }
}
