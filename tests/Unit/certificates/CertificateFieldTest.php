<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\certificates;

use BSBI\WebBase\certificates\CertificateField;
use BSBI\WebBase\certificates\CertificateTextAlign;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the CertificateField model.
 *
 * Covers colour parsing in its various hex forms, the fallback for malformed
 * colours, and the conditions under which a field shrinks its text to fit.
 */
final class CertificateFieldTest extends TestCase
{
    /**
     * Build a field, overriding only what a given test cares about.
     *
     * @param string $colour The hex colour to use
     * @param float $fontSize The font size in points
     * @param float $minFontSize The minimum font size for shrink-to-fit
     * @return CertificateField The field
     */
    private function makeField(
        string $colour = '#000000',
        float $fontSize = 40.0,
        float $minFontSize = 0.0
    ): CertificateField {
        return new CertificateField(
            'studentName',
            179.0,
            325.0,
            508.5,
            CertificateTextAlign::Centre,
            'dejavusans',
            $fontSize,
            $colour,
            '',
            $minFontSize
        );
    }

    /**
     * Verify a six digit hex colour is split into its RGB components.
     */
    public function testSixDigitHexColourIsParsed(): void
    {
        $this->assertSame([13, 59, 38], $this->makeField('#0d3b26')->getColourAsRgb());
    }

    /**
     * Verify a colour is parsed with or without its leading hash.
     */
    public function testLeadingHashIsOptional(): void
    {
        $this->assertSame([13, 59, 38], $this->makeField('0d3b26')->getColourAsRgb());
    }

    /**
     * Verify a three digit hex colour is expanded before being parsed.
     */
    public function testThreeDigitHexColourIsExpanded(): void
    {
        $this->assertSame([255, 204, 0], $this->makeField('#fc0')->getColourAsRgb());
    }

    /**
     * Verify a malformed colour falls back to black.
     *
     * A mistyped colour should produce readable text rather than an error or an
     * invisible field, since the failure would otherwise only surface on a
     * certificate that has already been sent.
     */
    public function testMalformedColourFallsBackToBlack(): void
    {
        $this->assertSame([0, 0, 0], $this->makeField('#nothex')->getColourAsRgb());
        $this->assertSame([0, 0, 0], $this->makeField('')->getColourAsRgb());
        $this->assertSame([0, 0, 0], $this->makeField('#12345')->getColourAsRgb());
    }

    /**
     * Verify shrink-to-fit is off when no minimum font size is set.
     */
    public function testShrinkToFitIsDisabledWithoutAMinimumSize(): void
    {
        $this->assertFalse($this->makeField('#000000', 40.0, 0.0)->shrinksToFit());
    }

    /**
     * Verify shrink-to-fit is on when a smaller minimum font size is set.
     */
    public function testShrinkToFitIsEnabledWithASmallerMinimumSize(): void
    {
        $this->assertTrue($this->makeField('#000000', 40.0, 18.0)->shrinksToFit());
    }

    /**
     * Verify a minimum at or above the font size disables shrink-to-fit.
     *
     * Such a field has no room to shrink into, so treating it as shrinkable would
     * mean looping without ever reducing the size.
     */
    public function testShrinkToFitIsDisabledWhenMinimumIsNotSmaller(): void
    {
        $this->assertFalse($this->makeField('#000000', 40.0, 40.0)->shrinksToFit());
        $this->assertFalse($this->makeField('#000000', 40.0, 48.0)->shrinksToFit());
    }

    /**
     * Verify the field exposes the values it was built with.
     */
    public function testFieldExposesItsGeometry(): void
    {
        $field = $this->makeField();

        $this->assertSame('studentName', $field->getKey());
        $this->assertSame(179.0, $field->getX());
        $this->assertSame(325.0, $field->getY());
        $this->assertSame(508.5, $field->getWidth());
        $this->assertSame(CertificateTextAlign::Centre, $field->getAlign());
        $this->assertSame('C', $field->getAlign()->toTcpdfAlign());
    }
}
