<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\certificates;

use BSBI\WebBase\certificates\CertificateDocument;
use BSBI\WebBase\certificates\CertificateException;
use BSBI\WebBase\certificates\CertificateTemplateInspector;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the CertificateTemplateInspector.
 *
 * The inspector exists to notice variable text left in a design by mistake, so
 * these tests are written around that job: a design with text must never be
 * reported as clean, which is the failure mode that matters.
 *
 * Fixtures are generated rather than committed, so the tests describe behaviour
 * against a known input instead of against a particular exported file.
 */
final class CertificateTemplateInspectorTest extends TestCase
{
    /** @var string[] Paths to remove once the test has finished */
    private array $temporaryFiles = [];

    /**
     * Remove any generated fixtures.
     */
    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $this->temporaryFiles = [];

        parent::tearDown();
    }

    /**
     * Generate a single page PDF containing the given text.
     *
     * @param string $text The text to draw, or an empty string for a blank page
     * @param string $fontFamily The font family to draw it with
     * @return string The path to the generated PDF
     */
    private function makePdf(string $text, string $fontFamily = 'dejavusans'): string
    {
        // CertificateDocument rather than a bare TCPDF: TCPDF stamps its own
        // "Powered by TCPDF" text onto every document, so a page built with it is
        // never genuinely blank and could not be used to test the empty case.
        $pdf = new CertificateDocument();
        $pdf->AddPage();

        if ($text !== '') {
            $pdf->SetFont($fontFamily, '', 24);
            $pdf->SetXY(50, 50);
            $pdf->Cell(400, 30, $text, 0, 0, 'L');
        }

        $path = (string)tempnam(sys_get_temp_dir(), 'cert-fixture-') . '.pdf';
        $pdf->Output($path, 'F');
        $this->temporaryFiles[] = $path;

        return $path;
    }

    /**
     * Verify text drawn on a design is reported.
     */
    public function testTextInADesignIsReported(): void
    {
        $inspector = new CertificateTemplateInspector();

        $this->assertTrue($inspector->hasText($this->makePdf('Alex Example')));
        $this->assertNotSame([], $inspector->extractText($this->makePdf('Alex Example')));
    }

    /**
     * Verify a design with no text at all is reported as clean.
     */
    public function testDesignWithoutTextIsReportedAsClean(): void
    {
        $this->assertFalse((new CertificateTemplateInspector())->hasText($this->makePdf('')));
    }

    /**
     * Verify text encoded by glyph index is still detected.
     *
     * Subset fonts encode text as bytes that routinely include NUL and TAB. An
     * earlier implementation trimmed those away with PHP's default trim charlist
     * and so reported a page full of text as empty — a false all-clear on exactly
     * the case this class exists to catch.
     */
    public function testGlyphEncodedTextIsNotTrimmedAway(): void
    {
        $inspector = new CertificateTemplateInspector();

        // A string whose glyph indices land on whitespace-like byte values.
        $this->assertTrue($inspector->hasText($this->makePdf("\t\t\t")));
    }

    /**
     * Verify the fonts a design references are reported without subset prefixes.
     *
     * This is the practical check for a name left in a design: the display face
     * used for the recipient's name appears nowhere else.
     */
    public function testFontNamesAreReportedWithoutSubsetPrefixes(): void
    {
        $fonts = (new CertificateTemplateInspector())->getFontNames($this->makePdf('Alex Example'));

        $this->assertNotSame([], $fonts);
        foreach ($fonts as $font) {
            $this->assertDoesNotMatchRegularExpression('/^[A-Z]{6}\+/', $font);
        }
    }

    /**
     * Verify a design's page count is reported.
     */
    public function testPageCountIsReported(): void
    {
        $this->assertSame(1, (new CertificateTemplateInspector())->getPageCount($this->makePdf('Alex Example')));
    }

    /**
     * Verify the PDF version is read from the header.
     */
    public function testPdfVersionIsReported(): void
    {
        $version = (new CertificateTemplateInspector())->getPdfVersion($this->makePdf('Alex Example'));

        $this->assertMatchesRegularExpression('/^\d+\.\d+$/', $version);
    }

    /**
     * Verify a usable design passes the check without complaint.
     */
    public function testUsableDesignPassesTheCheck(): void
    {
        $this->expectNotToPerformAssertions();

        (new CertificateTemplateInspector())->assertUsable($this->makePdf('Alex Example'));
    }

    /**
     * Verify asking for a page the design does not have is refused.
     */
    public function testPageBeyondTheDesignIsRefused(): void
    {
        $inspector = new CertificateTemplateInspector();

        $this->expectException(CertificateException::class);
        $this->expectExceptionMessage('page 4 cannot be used');

        $inspector->assertUsable($this->makePdf('Alex Example'), 4);
    }

    /**
     * Verify a design using compressed object streams is refused, not reported clean.
     *
     * PDF 1.5 introduced object streams, which the free PDF parser cannot decode
     * and which most current design tools produce by default. Reading font names
     * from the raw bytes finds nothing in such a file, so an earlier version
     * reported it as using no fonts at all — an all-clear from the check whose
     * entire purpose is to notice a name left in the design.
     */
    public function testDesignUsingObjectStreamsIsRefusedRatherThanReportedClean(): void
    {
        $path = (string)tempnam(sys_get_temp_dir(), 'cert-objstm-') . '.pdf';
        file_put_contents($path, "%PDF-1.7\n1 0 obj\n<< /Type /ObjStm /N 1 >>\nstream\nendstream\n");
        $this->temporaryFiles[] = $path;

        $inspector = new CertificateTemplateInspector();

        $this->expectException(CertificateException::class);
        $this->expectExceptionMessage('Re-export the design as PDF 1.4');

        $inspector->getFontNames($path);
    }

    /**
     * Verify an unreadable design produces a clear error rather than a silent pass.
     */
    public function testUnreadableDesignThrows(): void
    {
        $inspector = new CertificateTemplateInspector();

        $this->expectException(CertificateException::class);
        $this->expectExceptionMessage('could not be read');

        $inspector->extractText('/nonexistent/design.pdf');
    }

    /**
     * Verify an empty path produces a clear error.
     */
    public function testEmptyPathThrows(): void
    {
        $inspector = new CertificateTemplateInspector();

        $this->expectException(CertificateException::class);

        $inspector->getFontNames('');
    }
}
