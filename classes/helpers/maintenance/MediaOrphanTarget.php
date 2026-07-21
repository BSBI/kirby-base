<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers\maintenance;

/**
 * A single media hash directory the {@see MediaOrphanPolicy} has judged safe to delete,
 * carrying the reason it is orphaned so the Panel preview can label it.
 *
 * @package BSBI\WebBase\helpers\maintenance
 */
final readonly class MediaOrphanTarget
{
    /** The owning page no longer resolves — the whole media subtree is dead. */
    public const string REASON_MISSING_SOURCE = 'missing-source';

    /** The page resolves but this hash dir is not in its live set (file gone or stale mtime). */
    public const string REASON_STALE_HASH = 'stale-hash';

    /**
     * @param string $name the hash directory name (`<mediaToken>-<mtime>`)
     * @param string $reason one of the REASON_* constants
     */
    public function __construct(
        public string $name,
        public string $reason,
    ) {
    }
}
