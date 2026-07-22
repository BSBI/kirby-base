<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

use Kirby\Cms\App;
use Kirby\Toolkit\I18n;
use NumberFormatter;
use ReflectionProperty;
use Throwable;

/**
 * Guards Kirby's locale-aware number formatting against ICU-invalid language codes.
 *
 * PHP 8.4 changed NumberFormatter::__construct() to throw a ValueError when given an
 * invalid ICU locale, where PHP <= 8.3 emitted a warning and still returned a usable
 * (default-locale) formatter. Kirby's I18n::formatNumber() passes the active *language
 * code* straight to NumberFormatter, so any site whose Kirby language code is not a
 * valid ICU locale (e.g. "cyr" for Welsh — the real ICU code is "cy") fatals on every
 * front-end call that formats a number: File::niceSize(), tc()/translateCount(), or
 * formatNumber() directly, as soon as that language is the active one under PHP 8.4.
 *
 * Kirby caches one formatter per locale in the protected static I18n::$decimalsFormatters
 * array and returns the cached instance before ever constructing a new one. This guard
 * pre-seeds that cache for each offending language code with a formatter built from a
 * valid fallback locale, restoring the pre-8.4 graceful behaviour. It changes nothing
 * about translation lookups (which legitimately key off the same code), requires no patch
 * to the composer-managed Kirby core, and needs no change to the language code itself
 * (which is baked into every URL and every panel-managed translation key).
 */
final class LocaleNumberFormatterGuard
{
    /**
     * Fallback locale used to build a replacement formatter. English decimal formatting
     * ("1,234.5") is safe for file sizes and small counts across all languages.
     */
    public const string FALLBACK_LOCALE = 'en';

    /**
     * Pre-seed Kirby's decimal-formatter cache for every configured language whose code is
     * not a valid ICU locale, so number formatting degrades gracefully instead of throwing
     * under PHP 8.4.
     *
     * Best-effort: any failure (no intl extension, reflection unavailable, invalid fallback)
     * leaves the cache untouched. Single-language sites have no languages to iterate and are
     * a no-op.
     *
     * @param App|null $kirby The Kirby app whose languages to guard; defaults to the current instance.
     * @param string $fallbackLocale Valid ICU locale used to build the replacement formatter.
     * @return list<string> The language codes that were seeded with a fallback formatter.
     */
    public static function guardConfiguredLanguages(
        ?App $kirby = null,
        string $fallbackLocale = self::FALLBACK_LOCALE
    ): array {
        $kirby ??= App::instance();
        $seeded = [];

        foreach ($kirby->languages() as $language) {
            $code = $language->code();
            if (self::isValidIcuLocale($code) === false && self::seedFallbackFormatter($code, $fallbackLocale) === true) {
                $seeded[] = $code;
            }
        }

        return $seeded;
    }

    /**
     * Determine whether a locale code can construct a NumberFormatter without throwing under
     * the current PHP runtime.
     *
     * When intl is unavailable, Kirby's formatNumber() short-circuits to null and never throws,
     * so every code is reported valid (nothing to guard).
     *
     * @param string $code The locale/language code to test.
     * @return bool True if a formatter can be constructed for the code, false otherwise.
     */
    public static function isValidIcuLocale(string $code): bool
    {
        if (class_exists(NumberFormatter::class) === false) {
            return true;
        }

        try {
            // Constructed purely for its side-effect: under PHP 8.4 this throws on an
            // invalid ICU locale and succeeds otherwise. We care which, not the instance.
            new NumberFormatter($code, NumberFormatter::DECIMAL); // @phpstan-ignore new.resultUnused
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Seed Kirby's internal decimal-formatter cache for a given code with a formatter built
     * from a valid fallback locale, so I18n::formatNumber($n, $code) returns the cached
     * formatter instead of constructing one from the invalid code.
     *
     * @param string $code The (ICU-invalid) language code to seed a formatter for.
     * @param string $fallbackLocale Valid ICU locale used to build the replacement formatter.
     * @return bool True if the cache was seeded, false if it could not be (best-effort).
     */
    public static function seedFallbackFormatter(
        string $code,
        string $fallbackLocale = self::FALLBACK_LOCALE
    ): bool {
        if (class_exists(NumberFormatter::class) === false) {
            return false;
        }

        try {
            $formatter = new NumberFormatter($fallbackLocale, NumberFormatter::DECIMAL);
        } catch (Throwable) {
            return false;
        }

        try {
            $property = new ReflectionProperty(I18n::class, 'decimalsFormatters');
            /** @var array<string, NumberFormatter> $cache */
            $cache = $property->getValue();
            $cache[$code] = $formatter;
            $property->setValue(null, $cache);
        } catch (Throwable) {
            return false;
        }

        return true;
    }
}
