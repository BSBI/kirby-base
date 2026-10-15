<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\IndexRebuilds;
use BSBI\WebBase\Testing\KirbyTestEnvironment;
use Kirby\Exception\PermissionException;
use Kirby\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * The index rebuild routes (#52): rebuilds run only as POSTs through Kirby's API, which
 * checks the session and the CSRF token, never from a GET a link can trigger.
 */
final class IndexRebuildsTest extends TestCase
{
    private const array REBUILDS = [
        'indexes/rebuild/content',
        'indexes/rebuild/search',
        'indexes/rebuild/imagebank',
        'indexes/rebuild/filelinks',
    ];

    private const array OLD_URLS = [
        'content-index-rebuild',
        'search-rebuild',
        'imagebank-index-rebuild',
        'filelinks-index-rebuild',
    ];

    public static function setUpBeforeClass(): void
    {
        KirbyTestEnvironment::boot('kirby-base-index-rebuilds');
    }

    public static function tearDownAfterClass(): void
    {
        restore_error_handler();
        restore_exception_handler();
    }

    public function testEveryRebuildIsAPostApiRoute(): void
    {
        $routes = IndexRebuilds::apiRoutes();

        $this->assertSame(self::REBUILDS, array_column($routes, 'pattern'));
        foreach ($routes as $route) {
            $this->assertSame('POST', $route['method'], $route['pattern']);
            $this->assertNotNull(@\Closure::bind($route['action'], new \stdClass()), $route['pattern'] . ' cannot be bound');
        }
    }

    public function testTheOldGetUrlsRefuseWithoutRebuilding(): void
    {
        $routes = IndexRebuilds::retiredRoutes();

        $this->assertSame(self::OLD_URLS, array_column($routes, 'pattern'));
        foreach ($routes as $route) {
            // as Kirby's router calls it: bound to the route, which a static closure refuses
            $action = \Closure::bind($route['action'], new \stdClass());
            $this->assertNotNull($action, $route['pattern'] . ' cannot be bound');
            $response = @$action();
            $this->assertInstanceOf(Response::class, $response);
            $this->assertSame(405, $response->code(), $route['pattern']);
            $this->assertSame('POST', $response->header('Allow'));
        }
    }

    public function testEachRebuildActionReachesItsRebuildWhenKirbyRunsIt(): void
    {
        foreach (IndexRebuilds::apiRoutes() as $route) {
            try {
                // Kirby's API runs an action with Closure::call on itself, which moves
                // self:: to the Api class too; the rebuild must still be the one reached
                $route['action']->call(new class () {});
                $this->fail($route['pattern'] . ' rebuilt for a visitor');
            } catch (PermissionException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testARebuildRefusesAVisitorWhoIsNotAnAdminOrEditor(): void
    {
        $this->expectException(PermissionException::class);

        IndexRebuilds::content('');
    }
}
