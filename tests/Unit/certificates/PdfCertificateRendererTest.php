<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\certificates;

use BSBI\WebBase\certificates\CertificateDocument;
use BSBI\WebBase\certificates\CertificateException;
use BSBI\WebBase\certificates\CertificateField;
use BSBI\WebBase\certificates\CertificateOutputFormat;
use BSBI\WebBase\certificates\CertificateTemplate;
use BSBI\WebBase\certificates\CertificateTemplateInspector;
use BSBI\WebBase\certificates\CertificateTextAlign;
use BSBI\WebBase\certificates\PdfCertificateRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the PdfCertificateRenderer.
 *
 * Backgrounds are generated rather than committed, so these tests exercise the
 * renderer's own behaviour — page count, output format, error handling — without
 * depending on a particular design export.
 */
final class PdfCertificateRendererTest extends TestCase
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
     * Generate a blank A4 landscape background of the given page count.
     *
     * @param int $pages The number of pages the background should have
     * @return string The path to the generated PDF
     */
    private function makeBackground(int $pages = 1): string
    {
        $pdf = new CertificateDocument();

        for ($page = 0; $page < $pages; $page++) {
            $pdf->AddPage();
        }

        $path = (string)tempnam(sys_get_temp_dir(), 'cert-bg-') . '.pdf';
        $pdf->Output($path, 'F');
        $this->temporaryFiles[] = $path;

        return $path;
    }

    /**
     * Build a template over a generated background.
     *
     * @param string $sourcePath The background path
     * @param float $minFontSize The minimum font size for shrink-to-fit
     * @param int $sourcePage The page of the background to use
     * @return CertificateTemplate The template
     */
    private function makeTemplate(
        string $sourcePath,
        float $minFontSize = 0.0,
        int $sourcePage = 1
    ): CertificateTemplate {
        return new CertificateTemplate(
            'Test Certificate',
            $sourcePath,
            [new CertificateField(
                'studentName',
                179.0,
                325.0,
                508.5,
                CertificateTextAlign::Centre,
                'dejavusans',
                40.0,
                '#0d3b26',
                '',
                $minFontSize
            )],
            $sourcePage
        );
    }

    /**
     * Verify the renderer produces a PDF.
     */
    public function testRendersAPdf(): void
    {
        $result = (new PdfCertificateRenderer())->render(
            $this->makeTemplate($this->makeBackground()),
            ['studentName' => 'Alex Example'],
            'certificate.pdf'
        );

        $this->assertSame(CertificateOutputFormat::Pdf, $result->getFormat());
        $this->assertSame('application/pdf', $result->getMimeType());
        $this->assertSame('certificate.pdf', $result->getFilename());
        $this->assertStringStartsWith('%PDF', $result->getContents());
        $this->assertGreaterThan(0, $result->getSize());
    }

    /**
     * Verify a rendered certificate has exactly one page.
     *
     * TCPDF adds a page automatically when drawing runs past the bottom margin, so
     * a mispositioned or oversized field used to append a blank second page — which
     * would be posted out as part of the certificate.
     */
    public function testRenderedCertificateHasASinglePage(): void
    {
        $result = (new PdfCertificateRenderer())->render(
            $this->makeTemplate($this->makeBackground()),
            ['studentName' => 'Alex Example'],
            'certificate.pdf'
        );

        $this->assertSame(1, $this->countPages($result->getContents()));
    }

    /**
     * Verify a very long value still renders onto a single page.
     */
    public function testLongValueStillRendersASinglePage(): void
    {
        $result = (new PdfCertificateRenderer())->render(
            $this->makeTemplate($this->makeBackground(), 18.0),
            ['studentName' => 'Bartholomew Fotheringay-Ravensbourne-Whitworth'],
            'certificate.pdf'
        );

        $this->assertSame(1, $this->countPages($result->getContents()));
    }

    /**
     * Verify accented characters survive rendering.
     *
     * The default font is chosen specifically so that British and Irish names are
     * not mangled; a Latin-1 only font would silently drop these characters.
     */
    public function testAccentedNamesRender(): void
    {
        $result = (new PdfCertificateRenderer())->render(
            $this->makeTemplate($this->makeBackground()),
            ['studentName' => 'Siân Ó Briain-Müller'],
            'certificate.pdf'
        );

        $this->assertStringStartsWith('%PDF', $result->getContents());
        $this->assertSame(1, $this->countPages($result->getContents()));
    }

    /**
     * Verify a blank value renders without drawing anything or failing.
     */
    public function testBlankValueRendersWithoutError(): void
    {
        $result = (new PdfCertificateRenderer())->render(
            $this->makeTemplate($this->makeBackground()),
            ['studentName' => ''],
            'certificate.pdf'
        );

        $this->assertSame(1, $this->countPages($result->getContents()));
    }

    /**
     * Verify a missing design produces a clear error.
     */
    public function testMissingDesignThrows(): void
    {
        $this->expectException(CertificateException::class);
        $this->expectExceptionMessage('could not be read');

        (new PdfCertificateRenderer())->render(
            $this->makeTemplate('/nonexistent/design.pdf'),
            ['studentName' => 'Alex Example'],
            'certificate.pdf'
        );
    }

    /**
     * Verify requesting a page the design does not have produces a clear error.
     */
    public function testSourcePageBeyondTheDesignThrows(): void
    {
        $this->expectException(CertificateException::class);
        $this->expectExceptionMessage('page 3 was requested');

        (new PdfCertificateRenderer())->render(
            $this->makeTemplate($this->makeBackground(1), 0.0, 3),
            ['studentName' => 'Alex Example'],
            'certificate.pdf'
        );
    }

    /**
     * Verify no PDF library branding is left in the certificate.
     *
     * TCPDF stamps "Powered by TCPDF (www.tcpdf.org)" onto the last page of every
     * document at 1pt in an invisible render mode. It does not show in the artwork,
     * so it survives visual review, but it sits in the text layer and appears the
     * moment anyone copies text out of a certificate BSBI has issued.
     */
    public function testNoPdfLibraryBrandingIsLeftInTheCertificate(): void
    {
        $result = (new PdfCertificateRenderer())->render(
            $this->makeTemplate($this->makeBackground()),
            ['studentName' => 'Alex Example'],
            'certificate.pdf'
        );

        $path = (string)tempnam(sys_get_temp_dir(), 'cert-brand-') . '.pdf';
        file_put_contents($path, $result->getContents());
        $this->temporaryFiles[] = $path;

        foreach ((new CertificateTemplateInspector())->extractText($path) as $text) {
            $this->assertStringNotContainsStringIgnoringCase('tcpdf', $text);
        }
    }

    /**
     * Verify the renderer advertises PDF support and nothing else.
     */
    public function testSupportsPdfOnly(): void
    {
        $renderer = new PdfCertificateRenderer();

        $this->assertTrue($renderer->supports(CertificateOutputFormat::Pdf));
        $this->assertFalse($renderer->supports(CertificateOutputFormat::Png));
    }

    /**
     * Count the pages in rendered PDF bytes.
     *
     * @param string $contents The rendered PDF
     * @return int The page count
     */
    private function countPages(string $contents): int
    {
        $path = (string)tempnam(sys_get_temp_dir(), 'cert-out-') . '.pdf';
        file_put_contents($path, $contents);
        $this->temporaryFiles[] = $path;

        return (new CertificateTemplateInspector())->getPageCount($path);
    }
}
