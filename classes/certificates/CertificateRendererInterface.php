<?php

declare(strict_types=1);

namespace BSBI\WebBase\certificates;

/**
 * Renders a certificate template plus merge data into a particular output format.
 *
 * Implementations advertise the formats they handle, so a new output — a web page,
 * a raster image, a different paper size — is added by writing a renderer rather
 * than by changing anything that already works.
 *
 * @package BSBI\WebBase
 */
interface CertificateRendererInterface
{
    /**
     * Whether this renderer can produce the given output format.
     *
     * @param CertificateOutputFormat $format The desired output format
     * @return bool True when this renderer handles that format
     */
    public function supports(CertificateOutputFormat $format): bool;

    /**
     * Render a certificate.
     *
     * @param CertificateTemplate $template The template to render
     * @param array<string, string> $mergeData The values to merge, keyed by merge key
     * @param string $filename The filename to advertise when downloading
     * @return CertificateResult The rendered certificate
     * @throws CertificateException If the template cannot be read or rendering fails
     */
    public function render(CertificateTemplate $template, array $mergeData, string $filename): CertificateResult;
}
