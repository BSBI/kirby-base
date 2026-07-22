<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\HtmlLangResolver;
use Kirby\Cms\App;
use PHPUnit\Framework\TestCase;

/**
 * Tests for HtmlLangResolver.
 *
 * Verifies that the resolver emits the *current* language as a valid BCP 47
 * lang attribute (plus direction) for multi-language sites, and falls back
 * sensibly on single-language sites where Kirby exposes no language object.
 *
 * See GitHub issue #612 (WCAG 3.1.1 Language of Page).
 *
 * The Apps are booted once in setUpBeforeClass: constructing an App registers
 * global error/exception handlers, which PHPUnit 12 flags as risky if it
 * happens inside a test method. Language variations are exercised by switching
 * the current language on the shared multi-language App.
 */
final class HtmlLangResolverTest extends TestCase
{
    private static App $multilang;
    private static App $singleLang;

    public static function setUpBeforeClass(): void
    {
        self::$multilang = new App([
            'roots'     => ['index' => sys_get_temp_dir() . '/html-lang-resolver-multi-test'],
            'languages' => [
                ['code' => 'en', 'name' => 'English', 'default' => true, 'direction' => 'ltr'],
                ['code' => 'cy', 'name' => 'Cymraeg', 'direction' => 'ltr', 'url' => '/cyr'],
                ['code' => 'ar', 'name' => 'Arabic', 'direction' => 'rtl'],
            ],
        ]);

        self::$singleLang = new App([
            'roots' => ['index' => sys_get_temp_dir() . '/html-lang-resolver-single-test'],
        ]);
    }

    /**
     * The Welsh language emits lang="cy" — the valid BCP 47 tag, not "cyr"
     * and not a hardcoded "en".
     */
    public function testWelshCurrentLanguageEmitsCy(): void
    {
        self::$multilang->setCurrentLanguage('cy');
        $resolver = new HtmlLangResolver(self::$multilang);

        $this->assertSame('cy', $resolver->code());
        $this->assertSame('lang="cy" dir="ltr"', $resolver->attributes());
    }

    /**
     * The default (English) language emits lang="en".
     */
    public function testDefaultLanguageEmitsEn(): void
    {
        self::$multilang->setCurrentLanguage('en');
        $resolver = new HtmlLangResolver(self::$multilang);

        $this->assertSame('en', $resolver->code());
        $this->assertSame('lang="en" dir="ltr"', $resolver->attributes());
    }

    /**
     * Direction is taken from the active language (right-to-left here).
     */
    public function testDirectionReflectsActiveLanguage(): void
    {
        self::$multilang->setCurrentLanguage('ar');
        $resolver = new HtmlLangResolver(self::$multilang);

        $this->assertSame('rtl', $resolver->direction());
        $this->assertSame('lang="ar" dir="rtl"', $resolver->attributes());
    }

    /**
     * On a single-language site Kirby exposes no language object; the resolver
     * falls back to a valid default rather than emitting an empty attribute.
     */
    public function testSingleLanguageSiteFallsBackToEnLtr(): void
    {
        $resolver = new HtmlLangResolver(self::$singleLang);

        $this->assertSame('en', $resolver->code());
        $this->assertSame('ltr', $resolver->direction());
        $this->assertSame('lang="en" dir="ltr"', $resolver->attributes());
    }
}
