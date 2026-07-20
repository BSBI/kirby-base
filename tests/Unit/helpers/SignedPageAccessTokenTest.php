<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\SignedPageAccessToken;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SignedPageAccessToken: HMAC-signed, time-limited capability tokens
 * that grant access to a specific subject id (e.g. an order id) via a link.
 *
 * The token binds a subject id to an expiry with an HMAC so that (a) it cannot be
 * transferred to a different subject, (b) the expiry cannot be extended by editing
 * the token, and (c) verification is constant-time. An empty secret always fails
 * closed.
 */
final class SignedPageAccessTokenTest extends TestCase
{
    private const string SECRET = 'test-signing-secret';

    public function testValidTokenVerifies(): void
    {
        $token = SignedPageAccessToken::create('order-123', 2000, self::SECRET);
        self::assertTrue(SignedPageAccessToken::verify('order-123', $token, self::SECRET, 1000));
    }

    public function testExpiredTokenIsRejected(): void
    {
        $token = SignedPageAccessToken::create('order-123', 2000, self::SECRET);
        self::assertFalse(SignedPageAccessToken::verify('order-123', $token, self::SECRET, 2000));
        self::assertFalse(SignedPageAccessToken::verify('order-123', $token, self::SECRET, 2001));
    }

    public function testTokenForOtherSubjectIsRejected(): void
    {
        $token = SignedPageAccessToken::create('order-123', 2000, self::SECRET);
        self::assertFalse(SignedPageAccessToken::verify('order-999', $token, self::SECRET, 1000));
    }

    public function testTamperedExpiryIsRejected(): void
    {
        $token = SignedPageAccessToken::create('order-123', 2000, self::SECRET);
        // Move the expiry far into the future while keeping the original signature.
        $sig = explode('.', $token, 2)[1];
        $forged = '9999999999.' . $sig;
        self::assertFalse(SignedPageAccessToken::verify('order-123', $forged, self::SECRET, 1000));
    }

    public function testTamperedSignatureIsRejected(): void
    {
        $token = SignedPageAccessToken::create('order-123', 2000, self::SECRET);
        [$exp] = explode('.', $token, 2);
        $forged = $exp . '.' . str_repeat('0', 64);
        self::assertFalse(SignedPageAccessToken::verify('order-123', $forged, self::SECRET, 1000));
    }

    public function testWrongSecretIsRejected(): void
    {
        $token = SignedPageAccessToken::create('order-123', 2000, self::SECRET);
        self::assertFalse(SignedPageAccessToken::verify('order-123', $token, 'other-secret', 1000));
    }

    public function testEmptySecretFailsClosedOnVerify(): void
    {
        $token = SignedPageAccessToken::create('order-123', 2000, self::SECRET);
        self::assertFalse(SignedPageAccessToken::verify('order-123', $token, '', 1000));
    }

    public function testMalformedTokensAreRejected(): void
    {
        self::assertFalse(SignedPageAccessToken::verify('order-123', '', self::SECRET, 1000));
        self::assertFalse(SignedPageAccessToken::verify('order-123', 'no-dot-here', self::SECRET, 1000));
        self::assertFalse(SignedPageAccessToken::verify('order-123', 'notanumber.abc', self::SECRET, 1000));
        self::assertFalse(SignedPageAccessToken::verify('order-123', '.', self::SECRET, 1000));
    }
}
