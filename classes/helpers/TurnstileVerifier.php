<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

use BSBI\WebBase\models\ActionStatus;
use Closure;
use Kirby\Http\Remote;

/**
 * Server-side verification of the Cloudflare Turnstile challenge token.
 *
 * A missing or rejected challenge token is an everyday event (bots POSTing
 * a form directly, or a user whose Turnstile widget failed to load) and is
 * reported as a failed ActionStatus carrying a friendly message the form
 * can show — it is never an exception. Only a missing secret key throws,
 * because that is a genuine site configuration error.
 *
 * @package BSBI\WebBase
 */
final readonly class TurnstileVerifier
{
    /** Turnstile HTML input field name */
    public const string FIELD_NAME = 'cf-turnstile-response';

    /** URL for the Turnstile verification */
    private const string VERIFICATION_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    private Closure $verificationService;

    /**
     * @param string|null $secretKey The configured turnstile.secretKey option value
     * @param Closure(string, string): array{0: int, 1: mixed}|null $verificationService
     *        Performs the verification call; receives the secret key and challenge
     *        token, returns [HTTP status code, decoded JSON body]. Defaults to a
     *        POST to the Cloudflare siteverify endpoint; injectable for tests.
     */
    public function __construct(
        private ?string $secretKey,
        ?Closure $verificationService = null
    ) {
        $this->verificationService = $verificationService
            ?? static function (string $secret, string $challenge): array {
                $response = Remote::request(self::VERIFICATION_URL, [
                    'method' => 'POST',
                    'data' => [
                        'secret' => $secret,
                        'response' => $challenge,
                    ],
                ]);
                return [$response->code() ?? 0, $response->json()];
            };
    }

    /**
     * Verifies a Turnstile challenge token against the Cloudflare siteverify API.
     *
     * @param string|null $challenge The cf-turnstile-response value from the request
     * @return ActionStatus success, or failure with a user-facing friendly message
     * @throws KirbyRetrievalException if the Turnstile secret key is not configured
     */
    public function verify(?string $challenge): ActionStatus
    {
        if ($this->secretKey === null || $this->secretKey === '') {
            throw new KirbyRetrievalException('The Turnstile secret key is not configured');
        }

        if ($challenge === null || $challenge === '') {
            return new ActionStatus(
                false,
                'The Turnstile challenge was missing from the request',
                'The security check was not completed. Please try again.'
            );
        }

        try {
            [$code, $jsonResponse] = ($this->verificationService)($this->secretKey, $challenge);
        } catch (\Exception) {
            return new ActionStatus(
                false,
                'Error when trying to verify the Turnstile response',
                'We could not verify the security check. Please try again.'
            );
        }

        if ($code !== 200
            || !is_array($jsonResponse)
            || !isset($jsonResponse['success'])
            || $jsonResponse['success'] !== true
        ) {
            return new ActionStatus(
                false,
                'Turnstile rejected this input',
                'The security check failed. Please try again.'
            );
        }

        return new ActionStatus(true);
    }
}
