<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\certificates;

use BSBI\WebBase\certificates\CertificateIssue;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the certificate id carried by an issue record.
 *
 * The certificate id is the printable, permanent identity of an award — the
 * deliberate opposite of the reference, which is a revocable security token.
 * These tests pin the properties that make it safe to print: it survives every
 * transition an award can go through, and it round-trips through storage.
 */
final class CertificateIssueTest extends TestCase
{
    /**
     * Build an issue record carrying a certificate id.
     *
     * @param string $certificateId The certificate id to carry
     * @return CertificateIssue The record
     */
    private function makeIssue(string $certificateId = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'): CertificateIssue
    {
        return new CertificateIssue(
            'student-1',
            'course-100-plants',
            '2026-07-28',
            '100 Plants Challenge',
            'ref-1234',
            '2026-07-29',
            $certificateId
        );
    }

    /**
     * Verify a generated certificate id is a v4-format GUID.
     */
    public function testGeneratedCertificateIdIsAGuid(): void
    {
        $id = CertificateIssue::generateCertificateId();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $id
        );
    }

    /**
     * Verify two generated certificate ids differ.
     */
    public function testGeneratedCertificateIdsAreUnique(): void
    {
        $this->assertNotSame(
            CertificateIssue::generateCertificateId(),
            CertificateIssue::generateCertificateId()
        );
    }

    /**
     * Verify the certificate id round-trips through the stored shape.
     */
    public function testCertificateIdRoundTripsThroughItsStoredShape(): void
    {
        $issue = $this->makeIssue();

        $restored = CertificateIssue::fromArray($issue->toArray());

        $this->assertSame($issue->getCertificateId(), $restored->getCertificateId());
    }

    /**
     * Verify a record stored before certificate ids existed loads with none.
     */
    public function testStoredDataWithoutACertificateIdLoads(): void
    {
        $issue = CertificateIssue::fromArray(['recipient' => 'student-1', 'context' => 'course-1']);

        $this->assertSame('', $issue->getCertificateId());
    }

    /**
     * Verify a re-award keeps the certificate id.
     *
     * A re-award corrects a date or a design; the certificate it corrects is
     * still the same award, so its printed identity must not change.
     */
    public function testReawardingKeepsTheCertificateId(): void
    {
        $issue = $this->makeIssue()->reawarded('2026-08-01', 'New Design');

        $this->assertSame('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', $issue->getCertificateId());
    }

    /**
     * Verify replacing the reference keeps the certificate id.
     *
     * Revoking links is about the reference alone — a certificate already
     * printed with its id must still match the stored record afterwards.
     */
    public function testANewReferenceKeepsTheCertificateId(): void
    {
        $issue = $this->makeIssue()->withNewReference();

        $this->assertSame('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', $issue->getCertificateId());
    }

    /**
     * Verify recording a send keeps the certificate id.
     */
    public function testRecordingASendKeepsTheCertificateId(): void
    {
        $issue = $this->makeIssue()->withEmailedOn('2026-08-02');

        $this->assertSame('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', $issue->getCertificateId());
    }
}
