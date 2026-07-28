<?php

declare(strict_types=1);

namespace BSBI\WebBase\certificates;

/**
 * A rendered certificate, held in memory rather than written to disk.
 *
 * Certificates are deliberately not persisted by the renderer: they are cheap to
 * regenerate from the issue record, and keeping them transient avoids both
 * unbounded disk growth and stale copies bearing an out-of-date name.
 *
 * @package BSBI\WebBase
 */
final readonly class CertificateResult
{
    /**
     * @param string $contents The raw bytes of the rendered certificate
     * @param CertificateOutputFormat $format The format the certificate was rendered to
     * @param string $filename The filename to advertise when downloading
     */
    public function __construct(
        private string $contents,
        private CertificateOutputFormat $format,
        private string $filename
    ) {
    }

    /**
     * The raw bytes of the rendered certificate.
     *
     * @return string The certificate contents
     */
    public function getContents(): string
    {
        return $this->contents;
    }

    /**
     * The format the certificate was rendered to.
     *
     * @return CertificateOutputFormat The output format
     */
    public function getFormat(): CertificateOutputFormat
    {
        return $this->format;
    }

    /**
     * The MIME type to serve this certificate with.
     *
     * @return string The MIME type
     */
    public function getMimeType(): string
    {
        return $this->format->mimeType();
    }

    /**
     * The filename to advertise when downloading.
     *
     * @return string The filename
     */
    public function getFilename(): string
    {
        return $this->filename;
    }

    /**
     * The size of the rendered certificate in bytes.
     *
     * @return int The size in bytes
     */
    public function getSize(): int
    {
        return strlen($this->contents);
    }
}
