<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\KirbyRetrievalException;
use BSBI\WebBase\helpers\TurnstileVerifier;
use PHPUnit\Framework\TestCase;

/**
 * Tests for TurnstileVerifier — server-side verification of the Cloudflare
 * Turnstile challenge token.
 *
 * A missing challenge token is an everyday event (bots POSTing directly, or
 * a user whose Turnstile widget failed to load) and must come back as a
 * failed ActionStatus with a friendly message — not an exception. Only a
 * missing secret key (a genuine configuration error) throws.
 */
final class TurnstileVerifierTest extends TestCase
{
    private const string SECRET = 'test-secret-key';

    public function testMissingChallengeFailsWithoutCallingVerificationService(): void
    {
        $called = false;
        $verifier = new TurnstileVerifier(self::SECRET, function () use (&$called): array {
            $called = true;
            return [200, ['success' => true]];
        });

        foreach ([null, ''] as $challenge) {
            $status = $verifier->verify($challenge);
            $this->assertFalse($status->getStatus());
            $this->assertSame(
                'The security check was not completed. Please try again.',
                $status->getFirstFriendlyMessage()
            );
        }

        $this->assertFalse($called);
    }

    public function testMissingSecretKeyThrowsConfigurationException(): void
    {
        foreach ([null, ''] as $secret) {
            $verifier = new TurnstileVerifier($secret, static fn (): array => [200, ['success' => true]]);

            try {
                $verifier->verify('a-challenge-token');
                $this->fail('Expected KirbyRetrievalException for missing secret key');
            } catch (KirbyRetrievalException $e) {
                $this->assertSame('The Turnstile secret key is not configured', $e->getMessage());
            }
        }
    }

    public function testSuccessfulVerificationReturnsTrueStatus(): void
    {
        $verifier = new TurnstileVerifier(self::SECRET, static fn (): array => [200, ['success' => true]]);

        $status = $verifier->verify('a-challenge-token');

        $this->assertTrue($status->getStatus());
    }

    public function testVerificationServiceReceivesSecretAndChallenge(): void
    {
        $received = [];
        $verifier = new TurnstileVerifier(
            self::SECRET,
            function (string $secret, string $challenge) use (&$received): array {
                $received = [$secret, $challenge];
                return [200, ['success' => true]];
            }
        );

        $verifier->verify('a-challenge-token');

        $this->assertSame([self::SECRET, 'a-challenge-token'], $received);
    }

    public function testRejectedChallengeFailsWithFriendlyMessage(): void
    {
        $verifier = new TurnstileVerifier(self::SECRET, static fn (): array => [200, ['success' => false]]);

        $status = $verifier->verify('a-challenge-token');

        $this->assertFalse($status->getStatus());
        $this->assertSame(
            'The security check failed. Please try again.',
            $status->getFirstFriendlyMessage()
        );
    }

    public function testNon200ResponseFails(): void
    {
        $verifier = new TurnstileVerifier(self::SECRET, static fn (): array => [500, null]);

        $status = $verifier->verify('a-challenge-token');

        $this->assertFalse($status->getStatus());
    }

    public function testMissingSuccessKeyFails(): void
    {
        $verifier = new TurnstileVerifier(self::SECRET, static fn (): array => [200, ['other' => 'value']]);

        $status = $verifier->verify('a-challenge-token');

        $this->assertFalse($status->getStatus());
    }

    public function testVerificationServiceExceptionFailsGracefully(): void
    {
        $verifier = new TurnstileVerifier(self::SECRET, static function (): array {
            throw new \RuntimeException('network down');
        });

        $status = $verifier->verify('a-challenge-token');

        $this->assertFalse($status->getStatus());
        $this->assertSame(
            'We could not verify the security check. Please try again.',
            $status->getFirstFriendlyMessage()
        );
    }
}
