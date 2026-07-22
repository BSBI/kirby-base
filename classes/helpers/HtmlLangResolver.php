<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

use Kirby\Cms\App;

/**
 * Resolves the HTML `lang` and `dir` attributes for the *current* request
 * language, so front-end markup declares the actual page language rather than
 * a hardcoded value.
 *
 * Emitting the wrong `lang` is a WCAG 2.2 Success Criterion 3.1.1 (Language of
 * Page, Level A) failure — e.g. Welsh content announced by an English speech
 * engine. See GitHub issue #612.
 *
 * Kirby language codes are expected to be valid BCP 47 tags (`en`, `cy`). On a
 * single-language site Kirby exposes no language object, so the resolver falls
 * back to a sensible English/left-to-right default.
 *
 * The App is injected so the resolver is testable without global state.
 */
final readonly class HtmlLangResolver
{
    private const string DEFAULT_CODE = 'en';
    private const string DEFAULT_DIRECTION = 'ltr';

    /**
     * @param App $kirby
     */
    public function __construct(
        private App $kirby,
    ) {
    }

    /**
     * The BCP 47 language tag for the current (or default) language.
     *
     * @return string
     */
    public function code(): string
    {
        return $this->activeLanguageCode() ?? self::DEFAULT_CODE;
    }

    /**
     * The text direction (`ltr`/`rtl`) for the current (or default) language.
     *
     * @return string
     */
    public function direction(): string
    {
        $language = $this->kirby->language() ?? $this->kirby->defaultLanguage();

        return $language?->direction() ?? self::DEFAULT_DIRECTION;
    }

    /**
     * The ready-to-print `lang` and `dir` attribute string, e.g.
     * `lang="cy" dir="ltr"`.
     *
     * @return string
     */
    public function attributes(): string
    {
        return sprintf(
            'lang="%s" dir="%s"',
            htmlspecialchars($this->code(), ENT_QUOTES),
            htmlspecialchars($this->direction(), ENT_QUOTES),
        );
    }

    /**
     * The current language code, falling back to the default language code.
     * Null only on a single-language site (no language objects at all).
     *
     * @return string|null
     */
    private function activeLanguageCode(): ?string
    {
        $language = $this->kirby->language() ?? $this->kirby->defaultLanguage();

        return $language?->code();
    }
}
