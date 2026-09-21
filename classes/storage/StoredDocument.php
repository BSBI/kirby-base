<?php

declare(strict_types=1);

namespace BSBI\WebBase\storage;

/**
 * What the storage knows about one stored object once it is written: the key
 * and the facts a page record needs to describe it without opening it.
 */
final readonly class StoredDocument
{
    /**
     * @param string $key The object key
     * @param string $filename The filename as made safe for the key
     * @param int $size Bytes
     * @param string $mime The media type, as detected from the bytes
     */
    public function __construct(
        public string $key,
        public string $filename,
        public int $size,
        public string $mime,
    ) {
    }
}
