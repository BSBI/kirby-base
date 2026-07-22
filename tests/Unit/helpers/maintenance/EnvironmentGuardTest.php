<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers\maintenance;

use BSBI\WebBase\helpers\maintenance\EnvironmentGuard;
use BSBI\WebBase\helpers\maintenance\MaintenanceGuardException;
use PHPUnit\Framework\TestCase;

/**
 * Full truth table for the generic environment guard — the safety keystone for destructive
 * maintenance tasks. It must:
 *   - permit only environments on the positive allow-list (never `!== 'live'`), so any
 *     unrecognised/typo value, the empty string, and the production default all fail closed;
 *   - hard-refuse (defence in depth) whenever the app path contains a denied marker, even for
 *     an otherwise-permitted environment;
 *   - expose a non-throwing {@see EnvironmentGuard::isPermitted()} for the registration gate and
 *     a throwing {@see EnvironmentGuard::assertPermitted()} for per-task runtime re-assertion.
 */
final class EnvironmentGuardTest extends TestCase
{
    /** @var array<int, string> the BSBI-style non-production allow-list */
    private const array PERMITTED = ['staging', 'dev'];

    private const string LIVE_MARKER = 'eqscffqqhg';
    private const string STAGING_PATH = '/home/master/applications/cbheknbrut/public_html';
    private const string LIVE_PATH = '/home/master/applications/eqscffqqhg/public_html';

    // --- allow-list: permitted environments ---------------------------------

    public function testPermitsStagingWhenLiveMarkerAbsent(): void
    {
        $guard = new EnvironmentGuard('staging', self::PERMITTED, self::STAGING_PATH, [self::LIVE_MARKER]);

        self::assertTrue($guard->isPermitted());
    }

    public function testPermitsDevWhenLiveMarkerAbsent(): void
    {
        $guard = new EnvironmentGuard('dev', self::PERMITTED, '/Users/dev/site', [self::LIVE_MARKER]);

        self::assertTrue($guard->isPermitted());
    }

    // --- allow-list: refused environments (fail closed) ---------------------

    public function testRefusesLive(): void
    {
        $guard = new EnvironmentGuard('live', self::PERMITTED, self::STAGING_PATH, [self::LIVE_MARKER]);

        self::assertFalse($guard->isPermitted());
    }

    public function testRefusesProductionDefaultValue(): void
    {
        // The config resolves an absent environment.php to 'live' — the guard must refuse it.
        $guard = new EnvironmentGuard('live', self::PERMITTED);

        self::assertFalse($guard->isPermitted());
    }

    public function testRefusesTypoValueProvingPositiveAllowList(): void
    {
        // 'staign' is NOT 'live', so a `!== 'live'` guard would wrongly ARM the tools. A positive
        // allow-list must refuse anything it does not explicitly recognise.
        $guard = new EnvironmentGuard('staign', self::PERMITTED, self::STAGING_PATH, [self::LIVE_MARKER]);

        self::assertFalse($guard->isPermitted());
    }

    public function testRefusesEmptyEnvironment(): void
    {
        $guard = new EnvironmentGuard('', self::PERMITTED, self::STAGING_PATH, [self::LIVE_MARKER]);

        self::assertFalse($guard->isPermitted());
    }

    // --- defence in depth: denied path markers ------------------------------

    public function testRefusesWhenLiveMarkerPresentEvenIfEnvironmentIsStaging(): void
    {
        // The belt: a stray 'staging' config on the live box must still be caught by the path.
        $guard = new EnvironmentGuard('staging', self::PERMITTED, self::LIVE_PATH, [self::LIVE_MARKER]);

        self::assertFalse($guard->isPermitted());
    }

    public function testRefusesWhenLiveMarkerPresentEvenIfEnvironmentIsDev(): void
    {
        $guard = new EnvironmentGuard('dev', self::PERMITTED, self::LIVE_PATH, [self::LIVE_MARKER]);

        self::assertFalse($guard->isPermitted());
    }

    public function testRefusesWhenAnyOfSeveralDeniedMarkersMatches(): void
    {
        $guard = new EnvironmentGuard('staging', self::PERMITTED, self::LIVE_PATH, ['otherlive', self::LIVE_MARKER]);

        self::assertFalse($guard->isPermitted());
    }

    // --- path belt is refuse-only, never permit -----------------------------

    public function testPermitsWhenNoIndexPathConfigured(): void
    {
        // Empty path ⇒ no path-based belt to check ⇒ decision rests on the allow-list alone.
        $guard = new EnvironmentGuard('staging', self::PERMITTED, '', [self::LIVE_MARKER]);

        self::assertTrue($guard->isPermitted());
    }

    public function testPermitsWhenPathContainsNoDeniedMarker(): void
    {
        $guard = new EnvironmentGuard('staging', self::PERMITTED, self::STAGING_PATH, [self::LIVE_MARKER]);

        self::assertTrue($guard->isPermitted());
    }

    public function testEmptyMarkerStringNeverMatchesEverything(): void
    {
        // A '' marker would make str_contains() always true — it must be ignored, not refuse all.
        $guard = new EnvironmentGuard('staging', self::PERMITTED, self::STAGING_PATH, ['']);

        self::assertTrue($guard->isPermitted());
    }

    // --- assertPermitted() throwing contract --------------------------------

    public function testAssertPermittedIsSilentWhenPermitted(): void
    {
        $guard = new EnvironmentGuard('staging', self::PERMITTED, self::STAGING_PATH, [self::LIVE_MARKER]);

        $guard->assertPermitted();

        // No exception thrown.
        $this->addToAssertionCount(1);
    }

    public function testAssertPermittedThrowsOnRefusedEnvironment(): void
    {
        $guard = new EnvironmentGuard('live', self::PERMITTED);

        $this->expectException(MaintenanceGuardException::class);
        $this->expectExceptionMessage('live');

        $guard->assertPermitted();
    }

    public function testAssertPermittedThrowsOnDeniedPathMarker(): void
    {
        $guard = new EnvironmentGuard('staging', self::PERMITTED, self::LIVE_PATH, [self::LIVE_MARKER]);

        $this->expectException(MaintenanceGuardException::class);
        $this->expectExceptionMessage(self::LIVE_MARKER);

        $guard->assertPermitted();
    }
}
