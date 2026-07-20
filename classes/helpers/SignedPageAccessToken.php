<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

/**
 * HMAC-signed, time-limited capability token binding access to a subject id.
 *
 * A token is `<expiry>.<hmac>` where the HMAC signs `<id>|<expiry>` with a
 * server-side secret. This lets a link (e.g. in an order confirmation email)
 * grant access to one specific subject until an expiry, without storing per-
 * subject secrets:
 *   - the token cannot be replayed against a different subject id;
 *   - the expiry cannot be extended by editing the token (it is signed);
 *   - verification is constant-time (`hash_equals`);
 *   - an empty secret always fails closed.
 */
final class SignedPageAccessToken
{
    /**
     * Create a signed token granting access to $id until $expiry.
     *
     * @param string $id the subject the token authorises (e.g. an order id)
     * @param int $expiry unix timestamp after which the token is invalid
     * @param string $secret server-side signing secret
     * @return string the token, of the form "<expiry>.<hmac>"
     */
    public static function create(string $id, int $expiry, string $secret): string
    {
        return $expiry . '.' . self::sign($id, $expiry, $secret);
    }

    /**
     * Verify a token authorises $id and has not expired.
     *
     * @param string $id the subject the caller is trying to access
     * @param string $token the token presented (query param or cookie)
     * @param string $secret server-side signing secret; empty fails closed
     * @param int $now current unix timestamp
     * @return bool true only if the token is well-formed, correctly signed for
     *              $id, and $now is strictly before its expiry
     */
    public static function verify(string $id, string $token, string $secret, int $now): bool
    {
        if ($secret === '') {
            return false;
        }

        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return false;
        }

        [$expiry, $signature] = $parts;
        if ($expiry === '' || !ctype_digit($expiry)) {
            return false;
        }

        $expected = self::sign($id, (int) $expiry, $secret);
        if (!hash_equals($expected, $signature)) {
            return false;
        }

        return (int) $expiry > $now;
    }

    /**
     * Compute the HMAC-SHA256 signature over the id and expiry.
     *
     * @param string $id the subject
     * @param int $expiry unix timestamp
     * @param string $secret signing secret
     * @return string hex-encoded signature
     */
    private static function sign(string $id, int $expiry, string $secret): string
    {
        return hash_hmac('sha256', $id . '|' . $expiry, $secret);
    }
}
