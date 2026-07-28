<?php

declare(strict_types=1);

namespace BSBI\WebBase\certificates;

/**
 * Renders certificates, choosing a renderer for the requested output format.
 *
 * This is the entry point callers use. It is deliberately unaware of where the
 * recipient list came from — a course results sheet, a challenge, a one-off award —
 * so the same service serves any site that needs certificates.
 *
 * @package BSBI\WebBase
 */
final readonly class CertificateService
{
    /** @var CertificateRendererInterface[] The renderers available to this service */
    private array $renderers;

    /**
     * @param CertificateRendererInterface[] $renderers The renderers to choose between.
     *                                                  Defaults to PDF output only.
     */
    public function __construct(array $renderers = [])
    {
        $this->renderers = $renderers === [] ? [new PdfCertificateRenderer()] : $renderers;
    }

    /**
     * Render a certificate.
     *
     * @param CertificateTemplate $template The template to render
     * @param array<string, string> $mergeData The values to merge, keyed by merge key
     * @param CertificateOutputFormat $format The output format to render to
     * @param string|null $filename The filename to advertise, or null to derive one
     *                              from the template name and recipient
     * @return CertificateResult The rendered certificate
     * @throws CertificateException If merge data is missing, no renderer supports the
     *                              format, or rendering fails
     */
    public function render(
        CertificateTemplate $template,
        array $mergeData,
        CertificateOutputFormat $format = CertificateOutputFormat::Pdf,
        ?string $filename = null
    ): CertificateResult {
        $missing = $template->getMissingMergeKeys($mergeData);
        if ($missing !== []) {
            throw new CertificateException(sprintf(
                'Certificate "%s" is missing merge data for: %s',
                $template->getName(),
                implode(', ', $missing)
            ));
        }

        return $this->getRendererFor($format)->render(
            $template,
            $mergeData,
            $filename ?? $this->buildFilename($template, $mergeData, $format)
        );
    }

    /**
     * Find a renderer that supports the given output format.
     *
     * @param CertificateOutputFormat $format The desired output format
     * @return CertificateRendererInterface The first renderer supporting that format
     * @throws CertificateException If no renderer supports the format
     */
    private function getRendererFor(CertificateOutputFormat $format): CertificateRendererInterface
    {
        foreach ($this->renderers as $renderer) {
            if ($renderer->supports($format)) {
                return $renderer;
            }
        }

        throw new CertificateException(
            'No certificate renderer is available for ' . strtoupper($format->value) . ' output.'
        );
    }

    /**
     * Build a download filename from the template name and, where present, the
     * recipient's name.
     *
     * @param CertificateTemplate $template The template being rendered
     * @param array<string, string> $mergeData The merge data being rendered
     * @param CertificateOutputFormat $format The output format
     * @return string The filename, including extension
     */
    private function buildFilename(
        CertificateTemplate $template,
        array $mergeData,
        CertificateOutputFormat $format
    ): string {
        $parts = [$template->getName()];

        foreach (['studentName', 'recipientName', 'name'] as $key) {
            if (isset($mergeData[$key]) && trim($mergeData[$key]) !== '') {
                $parts[] = $mergeData[$key];
                break;
            }
        }

        $slug = strtolower(trim((string)preg_replace('/[^A-Za-z0-9]+/', '-', implode('-', $parts)), '-'));

        return ($slug === '' ? 'certificate' : $slug) . '.' . $format->extension();
    }
}
