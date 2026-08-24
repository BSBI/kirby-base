<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

use Kirby\Session\SessionData;

/**
 * Reads one-shot ("flash") values out of the Kirby session without writing to it
 * when there is nothing to read.
 *
 * Kirby's own SessionData::pull() calls prepareForWriting() before it looks at
 * whether the key exists, so a speculative pull on every page render takes an
 * exclusive lock on the session file and rewrites it on every request. That is
 * wasted work for the overwhelming majority of requests, it serialises a user's
 * concurrent requests behind one file lock, and it widens the window on a Kirby
 * race condition: if another request regenerated the session token in the
 * meantime, prepareForWriting() locks the *old* session file and then follows the
 * pointer to the new one, so the eventual commit() fatals during shutdown with
 * "Cannot write to session ..., because it is not locked".
 *
 * Reading first and only writing when there is something to clear keeps the
 * common path lock-free.
 */
final readonly class SessionFlash
{
    /**
     * @param SessionData $data The session's data object, e.g. kirby()->session()->data()
     */
    public function __construct(private SessionData $data)
    {
    }

    /**
     * Returns the stored value for the given key and clears it, so it is only ever
     * read once. Returns null — without writing to the session — when the key is
     * not set.
     *
     * @param string $key The session key to read and clear
     * @return mixed The stored value, or null when the key is absent
     */
    public function pull(string $key): mixed
    {
        $value = $this->data->get($key);

        if ($value === null) {
            return null;
        }

        $this->data->remove($key);

        return $value;
    }
}
