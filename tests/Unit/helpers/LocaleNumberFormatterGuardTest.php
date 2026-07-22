<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\LocaleNumberFormatterGuard;
use Kirby\Cms\App;
use Kirby\Toolkit\I18n;
use NumberFormatter;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Tests for LocaleNumberFormatterGuard: pre-seeding Kirby's decimal-formatter cache
 * so an ICU-invalid Kirby language code (e.g. "cyr") never fatals number formatting
 * under PHP 8.4, where NumberFormatter::__construct() throws on an invalid locale.
 *
 * Note: on PHP <= 8.3 an invalid locale like "cyr" does not throw (a warning was
 * emitted and a usable formatter returned), so which codes get seeded is
 * runtime-dependent. Assertions therefore target the version-independent contract —
 * a valid code is never seeded, and number formatting for the invalid code succeeds
 * after guarding — rather than an exact seeded-code list.
 */
final class LocaleNumberFormatterGuardTest extends TestCase
{
    private static App $app;

    /**
     * Boot a multilang app (valid "en" + ICU-invalid "cyr") once for the class.
     * Booting registers global handlers, so it is kept out of the per-test window.
     */
    public static function setUpBeforeClass(): void
    {
        self::$app = new App([
            'roots' => [
                'index' => sys_get_temp_dir() . '/locale-number-formatter-guard-' . uniqid(),
            ],
            'languages' => [
                ['code' => 'en', 'default' => true, 'name' => 'English'],
                ['code' => 'cyr', 'name' => 'Welsh'],
            ],
        ]);
    }

    /**
     * Remove any formatter we seeded so the shared static cache stays clean between tests.
     */
    protected function tearDown(): void
    {
        $property = new ReflectionProperty(I18n::class, 'decimalsFormatters');
        /** @var array<string, NumberFormatter> $cache */
        $cache = $property->getValue();
        unset($cache['cyr']);
        $property->setValue(null, $cache);
    }

    public function testValidIcuLocaleIsReportedValid(): void
    {
        self::assertTrue(LocaleNumberFormatterGuard::isValidIcuLocale('en'));
    }

    public function testSeedingLetsFormatNumberSucceedForInvalidCode(): void
    {
        // Guard against environments without intl; there Kirby returns null and never throws.
        if (class_exists(NumberFormatter::class) === false) {
            self::markTestSkipped('intl extension not available');
        }

        self::assertTrue(
            LocaleNumberFormatterGuard::seedFallbackFormatter('cyr'),
            'seeding the cache should succeed when intl is available'
        );

        // With the cache seeded, Kirby formats using the fallback (en) formatter
        // instead of constructing NumberFormatter('cyr') and throwing.
        self::assertSame('1,234.5', I18n::formatNumber(1234.5, 'cyr'));
    }

    public function testGuardConfiguredLanguagesLeavesWelshFormattable(): void
    {
        if (class_exists(NumberFormatter::class) === false) {
            self::markTestSkipped('intl extension not available');
        }

        $seeded = LocaleNumberFormatterGuard::guardConfiguredLanguages(self::$app);

        // A valid ICU code is never seeded — nothing to guard.
        self::assertNotContains('en', $seeded);

        // The whole point: after guarding, formatting a number for the Welsh code
        // does not fatal (it did under PHP 8.4 before the guard existed). The exact
        // grouping is runtime-dependent — "1,234.5" once the fallback is seeded on
        // 8.4, "1234.5" on <= 8.3 where the raw code formats natively — so match the
        // well-formed shape rather than a fixed string.
        self::assertMatchesRegularExpression(
            '/^1[.,]?234[.,]5$/',
            I18n::formatNumber(1234.5, 'cyr')
        );
    }
}
