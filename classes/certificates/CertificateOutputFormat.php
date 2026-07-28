<?php

declare(strict_types=1);

namespace BSBI\WebBase\certificates;

/**
 * The output formats a certificate can be rendered to.
 *
 * Renderers advertise which of these they support, so new formats can be added
 * without changing the calling code.
 *
 * @package BSBI\WebBase
 */
enum CertificateOutputFormat: string
{
    case Pdf = 'pdf';
    case Png = 'png';

    /**
     * The MIME type to serve this format with.
     *
     * @return string The MIME type
     */
    public function mimeType(): string
    {
        return match ($this) {
            self::Pdf => 'application/pdf',
            self::Png => 'image/png',
        };
    }

    /**
     * The file extension for this format, without a leading dot.
     *
     * @return string The file extension
     */
    public function extension(): string
    {
        return $this->value;
    }
}
