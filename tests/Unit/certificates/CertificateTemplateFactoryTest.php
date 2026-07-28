<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\certificates;

use BSBI\WebBase\certificates\CertificateTemplateFactory;
use BSBI\WebBase\certificates\CertificateTextAlign;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CertificateTemplateFactory.
 *
 * The rows this builds from are panel-edited, so the tests are mostly about what
 * happens to imperfect input: the aim is a usable certificate that can be
 * corrected and reissued, rather than a failure part-way through a run.
 */
final class CertificateTemplateFactoryTest extends TestCase
{
    /**
     * Build a template from the given rows.
     *
     * @param array<int, array<string, mixed>> $rows The configured rows
     * @return \BSBI\WebBase\certificates\CertificateTemplate The template
     */
    private function build(array $rows): \BSBI\WebBase\certificates\CertificateTemplate
    {
        return (new CertificateTemplateFactory())->build('Test', '/design.pdf', $rows);
    }

    /**
     * Verify a fully configured row becomes a field with those values.
     */
    public function testConfiguredRowBecomesAField(): void
    {
        $template = $this->build([[
            'key' => 'studentName',
            'x' => 163.82,
            'y' => 251.65,
            'width' => 514.57,
            'align' => 'centre',
            'fontSize' => 46.7,
            'minFontSize' => 20,
            'colour' => '#1b2a4a',
        ]]);

        $fields = $template->getFields();
        $this->assertCount(1, $fields);
        $this->assertSame('studentName', $fields[0]->getKey());
        $this->assertSame(163.82, $fields[0]->getX());
        $this->assertSame(251.65, $fields[0]->getY());
        $this->assertSame(514.57, $fields[0]->getWidth());
        $this->assertSame(46.7, $fields[0]->getFontSize());
        $this->assertTrue($fields[0]->shrinksToFit());
        $this->assertSame('#1b2a4a', $fields[0]->getColour());
    }

    /**
     * Verify numbers entered as strings are accepted.
     *
     * Panel number fields hand back strings often enough that rejecting them
     * would mean rejecting ordinary, correct configuration.
     */
    public function testNumbersGivenAsStringsAreAccepted(): void
    {
        $fields = $this->build([['key' => 'studentName', 'x' => '10.5', 'fontSize' => '32']])->getFields();

        $this->assertSame(10.5, $fields[0]->getX());
        $this->assertSame(32.0, $fields[0]->getFontSize());
    }

    /**
     * Verify a row with no merge key is dropped.
     *
     * A blank row is a common editing artefact and there is nothing to draw for
     * it, so it is skipped rather than producing an invisible empty field.
     */
    public function testRowWithoutAKeyIsDropped(): void
    {
        $template = $this->build([
            ['key' => '', 'x' => 10],
            ['key' => '   ', 'x' => 20],
            ['key' => 'studentName', 'x' => 30],
        ]);

        $this->assertCount(1, $template->getFields());
        $this->assertSame(['studentName'], $template->getMergeKeys());
    }

    /**
     * Verify a missing size falls back to a usable default rather than zero.
     *
     * A zero-point font would render nothing at all, which on a certificate looks
     * identical to the name having been forgotten.
     */
    public function testMissingFontSizeFallsBackToAUsableDefault(): void
    {
        $fields = $this->build([['key' => 'studentName']])->getFields();

        $this->assertGreaterThan(0.0, $fields[0]->getFontSize());
    }

    /**
     * Verify a non-numeric coordinate becomes zero rather than failing.
     */
    public function testNonNumericCoordinateBecomesZero(): void
    {
        $fields = $this->build([['key' => 'studentName', 'x' => 'not a number']])->getFields();

        $this->assertSame(0.0, $fields[0]->getX());
    }

    /**
     * Verify alignment is read case-insensitively.
     */
    public function testAlignmentIsReadCaseInsensitively(): void
    {
        $fields = $this->build([['key' => 'studentName', 'align' => 'RIGHT']])->getFields();

        $this->assertSame(CertificateTextAlign::Right, $fields[0]->getAlign());
    }

    /**
     * Verify an absent or unrecognised alignment falls back to centred.
     */
    public function testUnrecognisedAlignmentFallsBackToCentred(): void
    {
        $this->assertSame(
            CertificateTextAlign::Centre,
            $this->build([['key' => 'studentName', 'align' => 'sideways']])->getFields()[0]->getAlign()
        );
        $this->assertSame(
            CertificateTextAlign::Centre,
            $this->build([['key' => 'studentName']])->getFields()[0]->getAlign()
        );
    }

    /**
     * Verify a blank colour falls back to black rather than to nothing.
     */
    public function testBlankColourFallsBackToBlack(): void
    {
        $fields = $this->build([['key' => 'studentName', 'colour' => '  ']])->getFields();

        $this->assertSame('#000000', $fields[0]->getColour());
    }

    /**
     * Verify no configured rows produce a template with no fields.
     */
    public function testNoRowsProduceATemplateWithNoFields(): void
    {
        $template = $this->build([]);

        $this->assertSame([], $template->getFields());
        $this->assertSame([], $template->getMergeKeys());
    }

    /**
     * Verify the template carries the name, design and page it was built with.
     */
    public function testTemplateCarriesItsIdentity(): void
    {
        $template = (new CertificateTemplateFactory())
            ->build('Foundations in Plant ID', '/designs/foundations.pdf', [], 2);

        $this->assertSame('Foundations in Plant ID', $template->getName());
        $this->assertSame('/designs/foundations.pdf', $template->getSourcePath());
        $this->assertSame(2, $template->getSourcePage());
    }
}
