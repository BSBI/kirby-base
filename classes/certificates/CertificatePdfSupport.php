<?php

declare(strict_types=1);

namespace BSBI\WebBase\certificates;

/**
 * Whether the libraries needed to render a certificate are installed.
 *
 * FPDI and TCPDF are **not** required by this plugin. Only the classes in this
 * namespace use them, and most consuming sites never render a certificate —
 * requiring them would make every site install a large PDF toolkit for a feature
 * it does not have. They are suggested instead, and a site that wants
 * certificates installs them itself.
 *
 * That makes "the libraries are missing" an ordinary case rather than an
 * accident, so it gets a real error naming the packages to install. Without
 * this the failure is a bare "class not found", which says what broke but not
 * what to do about it.
 *
 * @package BSBI\WebBase
 */
final readonly class CertificatePdfSupport
{
    /**
     * The classes to probe, and the package that provides each.
     *
     * Deliberately **not** `setasign\Fpdi\Tcpdf\Fpdi`, which is the class the
     * renderer actually extends. That one extends `TCPDF`, so autoloading it
     * without TCPDF present is a fatal error rather than a `false` — the very
     * situation this check exists to report politely. The two probed here are
     * self-contained and safe to autoload independently.
     *
     * @var array<class-string|string, string>
     */
    private const array REQUIRED = [
        'TCPDF' => 'tecnickcom/tcpdf',
        'setasign\Fpdi\PdfParser\PdfParser' => 'setasign/fpdi',
    ];

    /**
     * Whether every required library is present.
     *
     * @param array<string, string> $required Class name => package name
     * @return bool True when all of them can be loaded
     */
    public static function isAvailable(array $required = self::REQUIRED): bool
    {
        return self::missing($required) === [];
    }

    /**
     * Fail with a usable message when a required library is missing.
     *
     * @param array<string, string> $required Class name => package name
     * @return void
     * @throws CertificateException If any required library is absent
     */
    public static function assertAvailable(array $required = self::REQUIRED): void
    {
        $missing = self::missing($required);

        if ($missing === []) {
            return;
        }

        // Every missing package at once: being told to install one, doing so,
        // and then being told about the next is a poor way to discover you
        // needed two.
        throw new CertificateException(sprintf(
            'Certificates need %s, which %s not installed. kirby-base suggests %s rather than '
            . 'requiring them, because only certificate rendering uses them — add them to this '
            . 'site\'s composer.json.',
            implode(' and ', $missing),
            count($missing) === 1 ? 'is' : 'are',
            count($missing) === 1 ? 'it' : 'them'
        ));
    }

    /**
     * The packages whose classes cannot be loaded.
     *
     * @param array<string, string> $required Class name => package name
     * @return list<string> The missing package names, in the order given
     */
    private static function missing(array $required): array
    {
        $missing = [];

        foreach ($required as $class => $package) {
            if (!class_exists($class)) {
                $missing[] = $package;
            }
        }

        return array_values(array_unique($missing));
    }
}
