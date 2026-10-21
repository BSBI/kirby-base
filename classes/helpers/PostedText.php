<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

/**
 * Makes posted form input safe to handle as UTF-8.
 *
 * Browsers always send valid UTF-8, but spam bots often don't (mangled emoji
 * are typical). Kirby's esc() throws on invalid UTF-8, so a form that shows a
 * bot's text back to it, e.g. after a failed Turnstile check, would fail with
 * an unhandled exception instead of the usual "please try again" page.
 * Scrubbing at the point of reading replaces each invalid byte sequence with
 * "?" and leaves valid text untouched.
 */
final class PostedText
{
    /**
     * Scrubs a posted value: strings are made valid UTF-8, arrays are scrubbed
     * recursively (keys included) and anything else is returned unchanged.
     */
    public static function scrub(mixed $value): mixed
    {
        if (is_string($value)) {
            return mb_scrub($value, 'UTF-8');
        }
        if (is_array($value)) {
            $scrubbed = [];
            foreach ($value as $key => $item) {
                $scrubbed[is_string($key) ? mb_scrub($key, 'UTF-8') : $key] = self::scrub($item);
            }
            return $scrubbed;
        }
        return $value;
    }
}
