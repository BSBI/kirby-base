<?php

declare(strict_types=1);

namespace BSBI\WebBase\certificates;

/**
 * The fonts bundled with the plugin for certificate text.
 *
 * TCPDF only finds fonts in its own directory, so families shipped here have to
 * be named by file when first selected. The files are pre-converted TCPDF
 * definitions (regular weight only), committed with their licences in
 * fonts/certificates — converting at runtime would need a writable vendor
 * directory and would redo the work on every server.
 *
 * @package BSBI\WebBase
 */
final readonly class CertificateFontLibrary
{
    /**
     * Bundled family key => human-readable label, for building Panel options.
     *
     * Keys are the TCPDF family names, which come from the converted filenames.
     */
    public const array FAMILIES = [
        'gentiumbookplus' => 'Gentium Book Plus',
        'sourcesans3' => 'Source Sans 3',
    ];

    /**
     * The font definition file for a family, when the plugin bundles it.
     *
     * The family is validated to letters and digits before touching the
     * filesystem: it originates in Panel-edited content, and a family is only
     * ever a lookup key, never a path.
     *
     * @param string $family The TCPDF family name
     * @return string The absolute path to the definition file, or '' when the
     *                family is not bundled — TCPDF then searches its own fonts
     */
    public static function fontFile(string $family): string
    {
        if (!preg_match('/^[a-z0-9]+$/', $family) || !array_key_exists($family, self::FAMILIES)) {
            return '';
        }

        $path = dirname(__DIR__, 2) . '/fonts/certificates/' . $family . '.php';

        return is_readable($path) ? $path : '';
    }
}
