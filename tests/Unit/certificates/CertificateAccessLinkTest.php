<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\certificates;

use BSBI\WebBase\certificates\CertificateAccessLink;
use BSBI\WebBase\certificates\CertificateException;
use BSBI\WebBase\certificates\CertificateIssue;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CertificateAccessLink.
 *
 * These links let somebody fetch a certificate without signing in, so the tests
 * are written around the ways that could go wrong: a link outliving its welcome,
 * a link working for the wrong award, and a link surviving revocation.
 */
final class CertificateAccessLinkTest extends TestCase
{
    /** A fixed "now" so expiry behaviour is deterministic. */
    private const int NOW = 1_785_000_000;

    /**
     * Build an award with a reference.
     *
     * @param string $reference The reference to use
     * @return CertificateIssue The award
     */
    private function makeIssue(string $reference = 'abc123'): CertificateIssue
    {
        return new CertificateIssue('student-1', 'course-1', '2026-07-28', 'Test', $reference);
    }

    /**
     * Verify a freshly built link is accepted.
     */
    public function testFreshLinkIsAccepted(): void
    {
        $links = new CertificateAccessLink('secret');
        $issue = $this->makeIssue();

        $token = $links->createToken($issue, self::NOW);

        $this->assertTrue($links->verify($issue->getReference(), $token, self::NOW));
    }

    /**
     * Verify a link stops working once its lifetime has passed.
     */
    public function testExpiredLinkIsRefused(): void
    {
        $links = new CertificateAccessLink('secret', 365);
        $issue = $this->makeIssue();

        $token = $links->createToken($issue, self::NOW);

        $this->assertTrue($links->verify($issue->getReference(), $token, self::NOW + (364 * 86400)));
        $this->assertFalse($links->verify($issue->getReference(), $token, self::NOW + (366 * 86400)));
    }

    /**
     * Verify a token cannot be replayed against a different award.
     *
     * Without this, one recipient's link would fetch anybody's certificate by
     * swapping the reference in the address.
     */
    public function testTokenDoesNotWorkForADifferentReference(): void
    {
        $links = new CertificateAccessLink('secret');

        $token = $links->createToken($this->makeIssue('reference-one'), self::NOW);

        $this->assertFalse($links->verify('reference-two', $token, self::NOW));
    }

    /**
     * Verify replacing an award's reference invalidates links already sent.
     *
     * This is what makes reissuing a link meaningful: the previous one must stop
     * working, or a link sent in error could never be withdrawn.
     */
    public function testReissuingAReferenceInvalidatesTheOldLink(): void
    {
        $links = new CertificateAccessLink('secret');
        $issue = $this->makeIssue();
        $token = $links->createToken($issue, self::NOW);

        $reissued = $issue->withNewReference();

        $this->assertNotSame($issue->getReference(), $reissued->getReference());
        $this->assertFalse($links->verify($reissued->getReference(), $token, self::NOW));
    }

    /**
     * Verify a tampered token is refused.
     */
    public function testTamperedTokenIsRefused(): void
    {
        $links = new CertificateAccessLink('secret');
        $issue = $this->makeIssue();
        $token = $links->createToken($issue, self::NOW);

        $farFuture = (self::NOW + (3650 * 86400)) . '.' . explode('.', $token, 2)[1];

        $this->assertFalse($links->verify($issue->getReference(), $farFuture, self::NOW));
    }

    /**
     * Verify a token signed with a different secret is refused.
     */
    public function testTokenFromADifferentSecretIsRefused(): void
    {
        $token = (new CertificateAccessLink('one'))->createToken($this->makeIssue(), self::NOW);

        $this->assertFalse((new CertificateAccessLink('two'))->verify('abc123', $token, self::NOW));
    }

    /**
     * Verify a missing secret fails closed rather than open.
     */
    public function testMissingSecretFailsClosed(): void
    {
        $links = new CertificateAccessLink('');

        $this->assertFalse($links->verify('abc123', 'anything', self::NOW));
    }

    /**
     * Verify a link cannot be created without a signing secret.
     */
    public function testCreatingALinkWithoutASecretIsRefused(): void
    {
        $links = new CertificateAccessLink('');

        $this->expectException(CertificateException::class);
        $this->expectExceptionMessage('No certificate signing secret is configured');

        $links->createToken($this->makeIssue(), self::NOW);
    }

    /**
     * Verify an award with no reference cannot have a link created for it.
     *
     * Awards recorded before references existed fall into this case; they are
     * still downloadable from the dashboard, just not sendable.
     */
    public function testAwardWithoutAReferenceCannotBeLinked(): void
    {
        $links = new CertificateAccessLink('secret');

        $this->expectException(CertificateException::class);
        $this->expectExceptionMessage('has no reference');

        $links->createToken(new CertificateIssue('student-1', 'course-1', '2026-07-28'), self::NOW);
    }

    /**
     * Verify empty reference or token values are refused outright.
     */
    public function testEmptyValuesAreRefused(): void
    {
        $links = new CertificateAccessLink('secret');

        $this->assertFalse($links->verify('', 'token', self::NOW));
        $this->assertFalse($links->verify('abc123', '', self::NOW));
    }

    /**
     * Verify generated references are unguessable and unique.
     */
    public function testGeneratedReferencesAreRandomAndUnique(): void
    {
        $references = [];
        for ($i = 0; $i < 50; $i++) {
            $references[] = CertificateIssue::generateReference();
        }

        $this->assertCount(50, array_unique($references));
        foreach ($references as $reference) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $reference);
        }
    }
}
