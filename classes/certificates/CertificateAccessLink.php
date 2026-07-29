<?php

declare(strict_types=1);

namespace BSBI\WebBase\certificates;

use BSBI\WebBase\helpers\SignedPageAccessToken;

/**
 * Builds and checks the links that let somebody fetch a certificate without
 * signing in.
 *
 * Certificates are often awarded once a course has finished, by which point the
 * recipient may no longer have an account they can log into — so a link sent by
 * email has to stand on its own.
 *
 * Two things guard it, covering different failures:
 *
 *  - the award's reference is unguessable and belongs to one award, so a link
 *    can be withdrawn by replacing it without disturbing anybody else's;
 *  - the signed token fixes the expiry beyond editing, and means a reference
 *    that leaks by itself — in a log, a backup, a database dump — is not enough
 *    to fetch anything.
 *
 * Neither alone would do: a reference on its own cannot express an expiry that
 * survives being edited, and a signature on its own cannot be revoked without
 * invalidating every link ever issued.
 *
 * @package BSBI\WebBase
 */
final readonly class CertificateAccessLink
{
    /** How long a link stays valid when no other lifetime is given. */
    public const int DEFAULT_LIFETIME_DAYS = 365;

    /**
     * @param string $secret The server-side signing secret. An empty secret makes
     *                       every check fail, which is the safe direction: links
     *                       stop working rather than stop being verified.
     * @param int $lifetimeDays How long a newly built link stays valid
     */
    public function __construct(
        private string $secret,
        private int $lifetimeDays = self::DEFAULT_LIFETIME_DAYS
    ) {
    }

    /**
     * Build the token half of a link for an award.
     *
     * @param CertificateIssue $issue The award to build a link for
     * @param int $now The current unix timestamp
     * @return string The token
     * @throws CertificateException If the award has no reference to sign, or no
     *                              signing secret is configured
     */
    public function createToken(CertificateIssue $issue, int $now): string
    {
        if (!$issue->isLinkable()) {
            throw new CertificateException(
                'This certificate has no reference, so no link can be sent for it. Reissue it first.'
            );
        }

        if ($this->secret === '') {
            throw new CertificateException(
                'No certificate signing secret is configured, so certificate links cannot be created.'
            );
        }

        return SignedPageAccessToken::create(
            $issue->getReference(),
            $now + ($this->lifetimeDays * 86400),
            $this->secret
        );
    }

    /**
     * Whether a token authorises the given reference and has not expired.
     *
     * @param string $reference The reference presented in the link
     * @param string $token The token presented in the link
     * @param int $now The current unix timestamp
     * @return bool True only when the token is correctly signed for that
     *              reference and still within its lifetime
     */
    public function verify(string $reference, string $token, int $now): bool
    {
        if ($reference === '' || $token === '') {
            return false;
        }

        return SignedPageAccessToken::verify($reference, $token, $this->secret, $now);
    }

    /**
     * When a link built now would stop working.
     *
     * @param int $now The current unix timestamp
     * @return int The expiry as a unix timestamp
     */
    public function expiryFor(int $now): int
    {
        return $now + ($this->lifetimeDays * 86400);
    }
}
