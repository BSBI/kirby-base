<?php

declare(strict_types=1);

namespace BSBI\WebBase\certificates;

/**
 * A single merged field on a certificate: where it sits, and how it is drawn.
 *
 * Positions are expressed in points from the top-left of the visible page, which
 * is how a designer measures and how PDF text extraction reports coordinates.
 * This deliberately hides the PDF's native bottom-left origin and any non-zero
 * MediaBox offset, both of which are easy to get subtly wrong by a few points.
 *
 * @package BSBI\WebBase
 */
final readonly class CertificateField
{
    /**
     * @param string $key The merge key this field draws, e.g. 'studentName'
     * @param float $x Points from the left edge of the page to the left of the text box
     * @param float $y Points from the top edge of the page to the top of the text
     * @param float $width Width of the text box in points, used for alignment and shrink-to-fit
     * @param CertificateTextAlign $align Horizontal alignment within the box
     * @param string $fontFamily The font family to draw with
     * @param float $fontSize The font size in points
     * @param string $colour The text colour as a hex string, e.g. '#0d3b26'
     * @param string $fontStyle TCPDF style flags, e.g. '', 'B', 'I' or 'BI'
     * @param float $minFontSize The smallest size to shrink to when the text overflows
     *                           the box; 0.0 disables shrink-to-fit
     */
    public function __construct(
        private string $key,
        private float $x,
        private float $y,
        private float $width,
        private CertificateTextAlign $align,
        private string $fontFamily,
        private float $fontSize,
        private string $colour = '#000000',
        private string $fontStyle = '',
        private float $minFontSize = 0.0
    ) {
    }

    /**
     * The merge key this field draws.
     *
     * @return string The merge key
     */
    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * Points from the left edge of the page to the left of the text box.
     *
     * @return float The x position in points
     */
    public function getX(): float
    {
        return $this->x;
    }

    /**
     * Points from the top edge of the page to the top of the text.
     *
     * @return float The y position in points
     */
    public function getY(): float
    {
        return $this->y;
    }

    /**
     * Width of the text box in points.
     *
     * @return float The box width in points
     */
    public function getWidth(): float
    {
        return $this->width;
    }

    /**
     * Horizontal alignment within the box.
     *
     * @return CertificateTextAlign The alignment
     */
    public function getAlign(): CertificateTextAlign
    {
        return $this->align;
    }

    /**
     * The font family to draw with.
     *
     * @return string The font family
     */
    public function getFontFamily(): string
    {
        return $this->fontFamily;
    }

    /**
     * The font size in points.
     *
     * @return float The font size
     */
    public function getFontSize(): float
    {
        return $this->fontSize;
    }

    /**
     * TCPDF style flags for the font.
     *
     * @return string The style flags
     */
    public function getFontStyle(): string
    {
        return $this->fontStyle;
    }

    /**
     * The text colour as a hex string.
     *
     * @return string The hex colour
     */
    public function getColour(): string
    {
        return $this->colour;
    }

    /**
     * The smallest font size this field may shrink to when the text overflows.
     *
     * @return float The minimum font size, or 0.0 when shrink-to-fit is disabled
     */
    public function getMinFontSize(): float
    {
        return $this->minFontSize;
    }

    /**
     * Whether this field shrinks its text to fit the box rather than overflowing.
     *
     * @return bool True when shrink-to-fit is enabled
     */
    public function shrinksToFit(): bool
    {
        return $this->minFontSize > 0.0 && $this->minFontSize < $this->fontSize;
    }

    /**
     * The text colour as red, green and blue components in the range 0-255.
     *
     * Falls back to black when the colour is not a valid three or six digit hex
     * string, so a mistyped colour produces readable text rather than an error.
     *
     * @return array{0: int, 1: int, 2: int} The RGB components
     */
    public function getColourAsRgb(): array
    {
        $hex = ltrim($this->colour, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        if (strlen($hex) !== 6 || preg_match('/^[0-9a-fA-F]{6}$/', $hex) !== 1) {
            return [0, 0, 0];
        }

        return [
            (int)hexdec(substr($hex, 0, 2)),
            (int)hexdec(substr($hex, 2, 2)),
            (int)hexdec(substr($hex, 4, 2)),
        ];
    }
}
