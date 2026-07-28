<?php

declare(strict_types=1);

namespace BSBI\WebBase\certificates;

/**
 * Builds a certificate template from configuration rows.
 *
 * The rows come from panel-edited content, so every value is treated as
 * untrusted: a mistyped size or a blank coordinate produces a usable field with
 * a sane default rather than an error. A certificate that comes out slightly
 * wrong can be corrected and reissued; one that fails to generate part-way
 * through a run of several hundred cannot.
 *
 * The one exception is a row with no merge key, which is dropped — there is
 * nothing sensible to draw and a stray blank row is a common editing artefact.
 *
 * @package BSBI\WebBase
 */
final readonly class CertificateTemplateFactory
{
    /** The size drawn at when none is configured. */
    private const float DEFAULT_FONT_SIZE = 24.0;

    /**
     * Build a template from its configuration.
     *
     * @param string $name A human-readable name for the template
     * @param string $sourcePath Absolute path to the background PDF
     * @param array<int, array<string, mixed>> $rows The configured field rows
     * @param int $sourcePage The page of the source PDF to use
     * @return CertificateTemplate The template
     */
    public function build(string $name, string $sourcePath, array $rows, int $sourcePage = 1): CertificateTemplate
    {
        $fields = [];

        foreach ($rows as $row) {
            $key = trim($this->readString($row, 'key'));
            if ($key === '') {
                continue;
            }

            $fields[] = new CertificateField(
                $key,
                $this->readFloat($row, 'x'),
                $this->readFloat($row, 'y'),
                $this->readFloat($row, 'width'),
                $this->readAlign($row),
                $this->readString($row, 'fontFamily'),
                $this->readFloat($row, 'fontSize', self::DEFAULT_FONT_SIZE),
                $this->readColour($row),
                $this->readString($row, 'fontStyle'),
                $this->readFloat($row, 'minFontSize')
            );
        }

        return new CertificateTemplate($name, $sourcePath, $fields, $sourcePage);
    }

    /**
     * Read a configured value as a string.
     *
     * @param array<string, mixed> $row The configuration row
     * @param string $key The key to read
     * @return string The value, or an empty string
     */
    private function readString(array $row, string $key): string
    {
        $value = $row[$key] ?? '';

        return is_scalar($value) ? (string)$value : '';
    }

    /**
     * Read a configured value as a number.
     *
     * @param array<string, mixed> $row The configuration row
     * @param string $key The key to read
     * @param float $default The value to use when absent or not numeric
     * @return float The value
     */
    private function readFloat(array $row, string $key, float $default = 0.0): float
    {
        $value = $row[$key] ?? null;

        return is_numeric($value) ? (float)$value : $default;
    }

    /**
     * Read a configured alignment, defaulting to centred.
     *
     * Most certificate text sits on a centred line, so centring is both the
     * common case and the least surprising result for an unrecognised value.
     *
     * @param array<string, mixed> $row The configuration row
     * @return CertificateTextAlign The alignment
     */
    private function readAlign(array $row): CertificateTextAlign
    {
        return CertificateTextAlign::tryFrom(strtolower($this->readString($row, 'align')))
            ?? CertificateTextAlign::Centre;
    }

    /**
     * Read a configured colour, defaulting to black.
     *
     * @param array<string, mixed> $row The configuration row
     * @return string The hex colour
     */
    private function readColour(array $row): string
    {
        $colour = trim($this->readString($row, 'colour'));

        return $colour !== '' ? $colour : '#000000';
    }
}
