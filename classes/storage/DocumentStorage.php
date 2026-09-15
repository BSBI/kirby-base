<?php

declare(strict_types=1);

namespace BSBI\WebBase\storage;

/**
 * Where uploaded documents live, behind one interface: S3 on the servers, a
 * local directory for development, memory for tests. Callers never see a
 * filesystem path or a URL for an object; they get keys, streams and sizes.
 */
interface DocumentStorage
{
    /**
     * Store a local file under the ref's key.
     *
     * @param string $localPath A readable file on disk (an upload's temp file, or a legacy page file)
     * @throws DocumentStorageException When the file cannot be read or the object cannot be written
     */
    public function store(string $localPath, DocumentRef $ref): StoredDocument;

    /**
     * Store bytes already in memory (a thumbnail, say) under an explicit key.
     *
     * @throws DocumentStorageException When the object cannot be written
     */
    public function write(string $key, string $bytes, string $mime): StoredDocument;

    /**
     * A read stream of the object's bytes. The caller closes it.
     *
     * @return resource
     * @throws DocumentStorageException When the object does not exist or cannot be read
     */
    public function readStream(string $key);

    public function exists(string $key): bool;

    /**
     * @throws DocumentStorageException When the object does not exist
     */
    public function size(string $key): int;

    /**
     * @throws DocumentStorageException When the object does not exist
     */
    public function mime(string $key): string;

    /**
     * Delete an object; deleting one that is not there is not an error.
     *
     * @throws DocumentStorageException When the delete itself fails
     */
    public function delete(string $key): void;

    /**
     * @throws DocumentStorageException When the source does not exist or the copy fails
     */
    public function copy(string $from, string $to): void;

    /**
     * Every object key under a prefix, in no particular order.
     *
     * @return iterable<string>
     */
    public function list(string $prefix): iterable;

    /**
     * A time-limited URL that serves the object directly, where the backend
     * can make one (S3); null where it cannot (local, memory).
     */
    public function temporaryUrl(string $key, int $ttlSeconds = 900): ?string;
}
