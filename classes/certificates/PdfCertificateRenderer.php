<?php

declare(strict_types=1);

namespace BSBI\WebBase\certificates;

use Throwable;

/**
 * Renders certificates as PDFs by stamping merged fields onto the design.
 *
 * The design PDF is imported as a template and drawn as the page background, so
 * the output is the original artwork with text added rather than a reconstruction
 * of it. Fonts, colours and images in the design are preserved untouched.
 *
 * @package BSBI\WebBase
 */
final readonly class PdfCertificateRenderer implements CertificateRendererInterface
{
    /**
     * The default font family. DejaVu Sans ships with TCPDF and covers the full
     * range of accented characters found in British and Irish names, which the
     * PDF core fonts do not.
     */
    public const string DEFAULT_FONT_FAMILY = 'dejavusans';

    /**
     * The step, in points, by which a field's font size is reduced when shrinking
     * text to fit its box.
     */
    private const float SHRINK_STEP = 0.5;

    /**
     * Whether this renderer can produce the given output format.
     *
     * @param CertificateOutputFormat $format The desired output format
     * @return bool True when the format is PDF
     */
    public function supports(CertificateOutputFormat $format): bool
    {
        return $format === CertificateOutputFormat::Pdf;
    }

    /**
     * Render a certificate as a PDF.
     *
     * @param CertificateTemplate $template The template to render
     * @param array<string, string> $mergeData The values to merge, keyed by merge key
     * @param string $filename The filename to advertise when downloading
     * @return CertificateResult The rendered PDF
     * @throws CertificateException If the design cannot be read, or rendering fails
     */
    public function render(CertificateTemplate $template, array $mergeData, string $filename): CertificateResult
    {
        if (!$template->sourceExists()) {
            throw new CertificateException(
                'Certificate design could not be read: ' . $template->getSourcePath()
            );
        }

        try {
            $pdf = $this->startDocument();

            $pageCount = $pdf->setSourceFile($template->getSourcePath());
            if ($template->getSourcePage() > $pageCount) {
                throw new CertificateException(sprintf(
                    'Certificate design "%s" has %d page(s); page %d was requested.',
                    $template->getName(),
                    $pageCount,
                    $template->getSourcePage()
                ));
            }

            $imported = $pdf->importPage($template->getSourcePage());

            // getTemplateSize() returns false for a template it cannot measure.
            // Left unchecked that surfaces as an array offset error on a boolean
            // rather than as anything a reader could act on.
            $size = $pdf->getTemplateSize($imported);
            if (!is_array($size)) {
                throw new CertificateException(sprintf(
                    'Could not read the page size of certificate design "%s".',
                    $template->getName()
                ));
            }

            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($imported);

            foreach ($template->getFields() as $field) {
                $this->drawField($pdf, $field, $mergeData[$field->getKey()] ?? '');
            }

            $contents = $pdf->Output($filename, 'S');
        } catch (CertificateException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new CertificateException(
                'Failed to render certificate "' . $template->getName() . '": ' . $e->getMessage(),
                0,
                $e
            );
        }

        return new CertificateResult($contents, CertificateOutputFormat::Pdf, $filename);
    }

    /**
     * Create a document configured to draw nothing of its own.
     *
     * @return CertificateDocument The prepared document
     */
    private function startDocument(): CertificateDocument
    {
        return new CertificateDocument();
    }

    /**
     * Draw a single merged field onto the current page.
     *
     * @param CertificateDocument $pdf The document being built
     * @param CertificateField $field The field to draw
     * @param string $value The value to draw
     * @return void
     */
    private function drawField(CertificateDocument $pdf, CertificateField $field, string $value): void
    {
        if ($value === '') {
            return;
        }

        $family = $field->getFontFamily() !== '' ? $field->getFontFamily() : self::DEFAULT_FONT_FAMILY;
        $size = $this->fitFontSize($pdf, $field, $value, $family);

        $pdf->SetFont($family, $field->getFontStyle(), $size);

        [$red, $green, $blue] = $field->getColourAsRgb();
        $pdf->SetTextColor($red, $green, $blue);

        // TCPDF centres text vertically within the cell, so a cell whose height is
        // the font's own line height puts the top of the text at the field's y.
        // getCellHeight() takes an int; rounding a fractional size shifts the text
        // by at most a quarter of a point, which is below the printable threshold.
        $lineHeight = $pdf->getCellHeight((int)round($size));
        $pdf->SetXY($field->getX(), $field->getY());
        $pdf->Cell(
            $field->getWidth(),
            $lineHeight,
            $value,
            0,
            0,
            $field->getAlign()->toTcpdfAlign()
        );
    }

    /**
     * Work out the largest font size at or below the field's own size that fits
     * the value inside the field's box.
     *
     * Long names are the normal case this guards against: without it a
     * double-barrelled name overflows the design and runs into the border artwork.
     *
     * @param CertificateDocument $pdf The document being built
     * @param CertificateField $field The field being drawn
     * @param string $value The value to draw
     * @param string $family The resolved font family
     * @return float The font size to draw at, in points
     */
    private function fitFontSize(CertificateDocument $pdf, CertificateField $field, string $value, string $family): float
    {
        $size = $field->getFontSize();

        if (!$field->shrinksToFit()) {
            return $size;
        }

        while ($size > $field->getMinFontSize()) {
            $width = $pdf->GetStringWidth($value, $family, $field->getFontStyle(), $size);
            if ($width <= $field->getWidth()) {
                break;
            }
            $size -= self::SHRINK_STEP;
        }

        return max($size, $field->getMinFontSize());
    }
}
