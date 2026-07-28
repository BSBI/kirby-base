<?php

declare(strict_types=1);

namespace BSBI\WebBase\certificates;

/**
 * A certificate design plus the fields merged onto it.
 *
 * The design is supplied as an existing PDF — typically an export from whatever
 * tool produced the artwork — with the variable text removed. Rendering stamps
 * the merged fields onto that page, so the certificate keeps the designer's
 * artwork exactly rather than approximating it.
 *
 * @package BSBI\WebBase
 */
final readonly class CertificateTemplate
{
    /**
     * @param string $name A human-readable name for this template
     * @param string $sourcePath Absolute path to the background PDF
     * @param CertificateField[] $fields The fields merged onto the design
     * @param int $sourcePage The page of the source PDF to use as the background
     */
    public function __construct(
        private string $name,
        private string $sourcePath,
        private array $fields,
        private int $sourcePage = 1
    ) {
    }

    /**
     * A human-readable name for this template.
     *
     * @return string The template name
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Absolute path to the background PDF.
     *
     * @return string The source path
     */
    public function getSourcePath(): string
    {
        return $this->sourcePath;
    }

    /**
     * The page of the source PDF used as the background.
     *
     * @return int The page number, one-based
     */
    public function getSourcePage(): int
    {
        return $this->sourcePage;
    }

    /**
     * The fields merged onto the design.
     *
     * @return CertificateField[] The fields
     */
    public function getFields(): array
    {
        return $this->fields;
    }

    /**
     * The merge keys this template expects to be supplied with.
     *
     * @return string[] The merge keys
     */
    public function getMergeKeys(): array
    {
        return array_values(array_unique(
            array_map(static fn(CertificateField $field): string => $field->getKey(), $this->fields)
        ));
    }

    /**
     * Which of this template's merge keys are missing from the supplied data.
     *
     * Returned rather than thrown so the caller can decide whether a missing key
     * is fatal or simply renders as blank — a certificate with a silently absent
     * name is a worse outcome than a clear error, so callers should normally treat
     * a non-empty result as a failure.
     *
     * @param array<string, string> $mergeData The supplied merge data
     * @return string[] The merge keys with no corresponding entry in the data
     */
    public function getMissingMergeKeys(array $mergeData): array
    {
        return array_values(array_filter(
            $this->getMergeKeys(),
            static fn(string $key): bool => !array_key_exists($key, $mergeData)
        ));
    }

    /**
     * Whether the source PDF exists and is readable.
     *
     * @return bool True when the source PDF can be read
     */
    public function sourceExists(): bool
    {
        return $this->sourcePath !== '' && is_readable($this->sourcePath);
    }
}
