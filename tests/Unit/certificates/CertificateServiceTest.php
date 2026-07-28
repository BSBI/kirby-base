<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\certificates;

use BSBI\WebBase\certificates\CertificateException;
use BSBI\WebBase\certificates\CertificateField;
use BSBI\WebBase\certificates\CertificateOutputFormat;
use BSBI\WebBase\certificates\CertificateRendererInterface;
use BSBI\WebBase\certificates\CertificateResult;
use BSBI\WebBase\certificates\CertificateService;
use BSBI\WebBase\certificates\CertificateTemplate;
use BSBI\WebBase\certificates\CertificateTextAlign;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the CertificateService.
 *
 * Covers merge data validation, renderer selection by output format, and the
 * filenames generated for downloads. Rendering itself is stubbed so these tests
 * describe the service's own decisions rather than the PDF library's behaviour.
 */
final class CertificateServiceTest extends TestCase
{
    /**
     * Build a service whose only renderer records what it was asked to render.
     *
     * @param CertificateOutputFormat $supports The format the stub renderer claims
     * @return array{0: CertificateService, 1: CertificateRendererInterface} The service and its renderer
     */
    private function makeServiceWithStub(
        CertificateOutputFormat $supports = CertificateOutputFormat::Pdf
    ): array {
        $renderer = new class ($supports) implements CertificateRendererInterface {
            /** @var string The filename passed to the last render call */
            public string $lastFilename = '';

            /** @var array<string, string> The merge data passed to the last render call */
            public array $lastMergeData = [];

            /**
             * @param CertificateOutputFormat $supported The format this renderer claims to support
             */
            public function __construct(private CertificateOutputFormat $supported)
            {
            }

            /**
             * @param CertificateOutputFormat $format The format being asked about
             * @return bool True when this renderer claims that format
             */
            public function supports(CertificateOutputFormat $format): bool
            {
                return $format === $this->supported;
            }

            /**
             * @param CertificateTemplate $template The template to render
             * @param array<string, string> $mergeData The merge data
             * @param string $filename The filename to advertise
             * @return CertificateResult A stub result
             */
            public function render(
                CertificateTemplate $template,
                array $mergeData,
                string $filename
            ): CertificateResult {
                $this->lastFilename = $filename;
                $this->lastMergeData = $mergeData;

                return new CertificateResult('stub-bytes', $this->supported, $filename);
            }
        };

        return [new CertificateService([$renderer]), $renderer];
    }

    /**
     * Build a template over the given merge keys.
     *
     * @param string[] $keys The merge keys to create fields for
     * @param string $name The template name
     * @return CertificateTemplate The template
     */
    private function makeTemplate(array $keys = ['studentName'], string $name = 'Test Certificate'): CertificateTemplate
    {
        return new CertificateTemplate(
            $name,
            '/nonexistent/design.pdf',
            array_map(
                static fn(string $key): CertificateField => new CertificateField(
                    $key,
                    0.0,
                    0.0,
                    500.0,
                    CertificateTextAlign::Centre,
                    'dejavusans',
                    24.0
                ),
                $keys
            )
        );
    }

    /**
     * Verify rendering fails when the template's merge data has not been supplied.
     *
     * Rendering a certificate with a silently blank name is a worse outcome than a
     * failed batch, since it would be posted to the recipient before anyone noticed.
     */
    public function testMissingMergeDataThrows(): void
    {
        [$service] = $this->makeServiceWithStub();

        $this->expectException(CertificateException::class);
        $this->expectExceptionMessage('missing merge data for: year');

        $service->render($this->makeTemplate(['studentName', 'year']), ['studentName' => 'Alex Example']);
    }

    /**
     * Verify rendering fails when no renderer supports the requested format.
     */
    public function testUnsupportedFormatThrows(): void
    {
        [$service] = $this->makeServiceWithStub(CertificateOutputFormat::Pdf);

        $this->expectException(CertificateException::class);
        $this->expectExceptionMessage('No certificate renderer is available for PNG');

        $service->render($this->makeTemplate(), ['studentName' => 'Alex Example'], CertificateOutputFormat::Png);
    }

    /**
     * Verify the service passes the merge data through to the renderer.
     */
    public function testMergeDataReachesTheRenderer(): void
    {
        [$service, $renderer] = $this->makeServiceWithStub();

        $service->render($this->makeTemplate(), ['studentName' => 'Alex Example']);

        $this->assertSame(['studentName' => 'Alex Example'], $renderer->lastMergeData);
    }

    /**
     * Verify a filename is derived from the template name and the recipient.
     */
    public function testFilenameIsDerivedFromTemplateAndRecipient(): void
    {
        [$service, $renderer] = $this->makeServiceWithStub();

        $service->render($this->makeTemplate(['studentName'], '100 Plants Challenge'), [
            'studentName' => 'Alex Example',
        ]);

        $this->assertSame('100-plants-challenge-alex-example.pdf', $renderer->lastFilename);
    }

    /**
     * Verify an accented name produces a filename safe to send over HTTP.
     */
    public function testAccentedNamesProduceASafeFilename(): void
    {
        [$service, $renderer] = $this->makeServiceWithStub();

        $service->render($this->makeTemplate(['studentName'], 'Cert'), [
            'studentName' => 'Siân Ó Briain',
        ]);

        $this->assertMatchesRegularExpression('/^[a-z0-9\-]+\.pdf$/', $renderer->lastFilename);
    }

    /**
     * Verify an explicitly supplied filename is used unchanged.
     */
    public function testExplicitFilenameIsUsed(): void
    {
        [$service, $renderer] = $this->makeServiceWithStub();

        $service->render(
            $this->makeTemplate(),
            ['studentName' => 'Alex Example'],
            CertificateOutputFormat::Pdf,
            'chosen-name.pdf'
        );

        $this->assertSame('chosen-name.pdf', $renderer->lastFilename);
    }

    /**
     * Verify a template with no recipient key still produces a usable filename.
     */
    public function testFilenameFallsBackToTheTemplateName(): void
    {
        [$service, $renderer] = $this->makeServiceWithStub();

        $service->render($this->makeTemplate(['year'], 'Challenge'), ['year' => '2026']);

        $this->assertSame('challenge.pdf', $renderer->lastFilename);
    }

    /**
     * Verify the service defaults to PDF output when no format is given.
     */
    public function testDefaultOutputFormatIsPdf(): void
    {
        [$service] = $this->makeServiceWithStub();

        $result = $service->render($this->makeTemplate(), ['studentName' => 'Alex Example']);

        $this->assertSame(CertificateOutputFormat::Pdf, $result->getFormat());
        $this->assertSame('application/pdf', $result->getMimeType());
    }
}
