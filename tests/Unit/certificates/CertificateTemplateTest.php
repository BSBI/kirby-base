<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\certificates;

use BSBI\WebBase\certificates\CertificateField;
use BSBI\WebBase\certificates\CertificateTemplate;
use BSBI\WebBase\certificates\CertificateTextAlign;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the CertificateTemplate model.
 *
 * Covers merge key collection, detection of merge data that has not been
 * supplied, and reporting on whether the design file can be read.
 */
final class CertificateTemplateTest extends TestCase
{
    /**
     * Build a field for use in a template.
     *
     * @param string $key The merge key the field draws
     * @return CertificateField The field
     */
    private function makeField(string $key): CertificateField
    {
        return new CertificateField(
            $key,
            0.0,
            0.0,
            500.0,
            CertificateTextAlign::Centre,
            'dejavusans',
            24.0
        );
    }

    /**
     * Build a template over the given merge keys.
     *
     * @param string[] $keys The merge keys to create fields for
     * @param string $sourcePath The design path
     * @return CertificateTemplate The template
     */
    private function makeTemplate(array $keys, string $sourcePath = '/nonexistent/design.pdf'): CertificateTemplate
    {
        return new CertificateTemplate(
            'Test Certificate',
            $sourcePath,
            array_map(fn(string $key): CertificateField => $this->makeField($key), $keys)
        );
    }

    /**
     * Verify the template reports the merge keys its fields draw.
     */
    public function testMergeKeysAreCollectedFromFields(): void
    {
        $template = $this->makeTemplate(['studentName', 'year']);

        $this->assertSame(['studentName', 'year'], $template->getMergeKeys());
    }

    /**
     * Verify a key used by more than one field is reported only once.
     *
     * A name can legitimately appear twice on a certificate — on the face and on a
     * counterfoil — and the caller should only be asked for it once.
     */
    public function testRepeatedMergeKeysAreReportedOnce(): void
    {
        $template = $this->makeTemplate(['studentName', 'year', 'studentName']);

        $this->assertSame(['studentName', 'year'], $template->getMergeKeys());
    }

    /**
     * Verify a template with no fields reports no merge keys.
     */
    public function testTemplateWithoutFieldsHasNoMergeKeys(): void
    {
        $this->assertSame([], $this->makeTemplate([])->getMergeKeys());
    }

    /**
     * Verify fully supplied merge data reports nothing missing.
     */
    public function testCompleteMergeDataReportsNothingMissing(): void
    {
        $template = $this->makeTemplate(['studentName', 'year']);

        $this->assertSame([], $template->getMissingMergeKeys([
            'studentName' => 'Alex Example',
            'year' => '2026',
        ]));
    }

    /**
     * Verify merge keys with no corresponding data are reported.
     */
    public function testMissingMergeDataIsReported(): void
    {
        $template = $this->makeTemplate(['studentName', 'year']);

        $this->assertSame(['year'], $template->getMissingMergeKeys(['studentName' => 'Alex Example']));
    }

    /**
     * Verify a key supplied as an empty string counts as present.
     *
     * Blank is a legitimate value for an optional field; only a key that was never
     * supplied at all indicates the caller has forgotten something.
     */
    public function testEmptyStringCountsAsSupplied(): void
    {
        $template = $this->makeTemplate(['studentName']);

        $this->assertSame([], $template->getMissingMergeKeys(['studentName' => '']));
    }

    /**
     * Verify extra merge data that the template does not use is ignored.
     */
    public function testUnusedMergeDataIsIgnored(): void
    {
        $template = $this->makeTemplate(['studentName']);

        $this->assertSame([], $template->getMissingMergeKeys([
            'studentName' => 'Alex Example',
            'somethingElse' => 'ignored',
        ]));
    }

    /**
     * Verify a missing design file is reported as unreadable.
     */
    public function testMissingSourceIsReportedAsUnreadable(): void
    {
        $this->assertFalse($this->makeTemplate(['studentName'])->sourceExists());
        $this->assertFalse($this->makeTemplate(['studentName'], '')->sourceExists());
    }

    /**
     * Verify a readable design file is reported as present.
     */
    public function testReadableSourceIsReportedAsPresent(): void
    {
        $this->assertTrue($this->makeTemplate(['studentName'], __FILE__)->sourceExists());
    }

    /**
     * Verify the template defaults to the first page of its design.
     */
    public function testSourcePageDefaultsToOne(): void
    {
        $this->assertSame(1, $this->makeTemplate(['studentName'])->getSourcePage());
    }
}
