<?php

declare(strict_types=1);

namespace BSBI\WebBase\storage;

use DateTimeImmutable;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToGeneratePublicUrl;
use League\Flysystem\UnableToGenerateTemporaryUrl;
use League\Flysystem\Visibility;
use Throwable;

/**
 * DocumentStorage over any Flysystem filesystem: the S3 adapter on the
 * servers, the local adapter for development, the in-memory adapter for
 * tests. Objects are written private.
 */
final readonly class FlysystemDocumentStorage implements DocumentStorage
{
    public function __construct(private FilesystemOperator $filesystem)
    {
    }

    public function store(string $localPath, DocumentRef $ref): StoredDocument
    {
        $handle = @fopen($localPath, 'rb');
        if ($handle === false) {
            throw new DocumentStorageException('Cannot read ' . basename($localPath));
        }
        $key = $ref->key();
        try {
            $this->filesystem->writeStream($key, $handle, ['visibility' => Visibility::PRIVATE]);
        } catch (FilesystemException $e) {
            throw new DocumentStorageException('Cannot store ' . $key . ': ' . $e->getMessage(), 0, $e);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
        $mime = self::detectMime($localPath);
        return new StoredDocument($key, DocumentRef::safeFilename($ref->filename), (int) filesize($localPath), $mime);
    }

    public function write(string $key, string $bytes, string $mime): StoredDocument
    {
        try {
            $this->filesystem->write($key, $bytes, ['visibility' => Visibility::PRIVATE]);
        } catch (FilesystemException $e) {
            throw new DocumentStorageException('Cannot write ' . $key . ': ' . $e->getMessage(), 0, $e);
        }
        return new StoredDocument($key, basename($key), strlen($bytes), $mime);
    }

    public function readStream(string $key)
    {
        try {
            return $this->filesystem->readStream($key);
        } catch (FilesystemException $e) {
            throw new DocumentStorageException('Cannot read ' . $key . ': ' . $e->getMessage(), 0, $e);
        }
    }

    public function exists(string $key): bool
    {
        try {
            return $this->filesystem->fileExists($key);
        } catch (FilesystemException) {
            return false;
        }
    }

    public function size(string $key): int
    {
        try {
            return $this->filesystem->fileSize($key);
        } catch (FilesystemException $e) {
            throw new DocumentStorageException('Cannot size ' . $key . ': ' . $e->getMessage(), 0, $e);
        }
    }

    public function mime(string $key): string
    {
        try {
            return $this->filesystem->mimeType($key);
        } catch (FilesystemException $e) {
            throw new DocumentStorageException('Cannot read the type of ' . $key . ': ' . $e->getMessage(), 0, $e);
        }
    }

    public function delete(string $key): void
    {
        try {
            $this->filesystem->delete($key);
        } catch (FilesystemException $e) {
            throw new DocumentStorageException('Cannot delete ' . $key . ': ' . $e->getMessage(), 0, $e);
        }
    }

    public function copy(string $from, string $to): void
    {
        try {
            $this->filesystem->copy($from, $to);
        } catch (FilesystemException $e) {
            throw new DocumentStorageException('Cannot copy ' . $from . ': ' . $e->getMessage(), 0, $e);
        }
    }

    public function list(string $prefix): iterable
    {
        try {
            foreach ($this->filesystem->listContents($prefix, true) as $entry) {
                /** @var StorageAttributes $entry */
                if ($entry->isFile()) {
                    yield $entry->path();
                }
            }
        } catch (FilesystemException $e) {
            throw new DocumentStorageException('Cannot list ' . $prefix . ': ' . $e->getMessage(), 0, $e);
        }
    }

    public function temporaryUrl(string $key, int $ttlSeconds = 900): ?string
    {
        try {
            return $this->filesystem->temporaryUrl($key, new DateTimeImmutable('+' . max(1, $ttlSeconds) . ' seconds'));
        } catch (UnableToGenerateTemporaryUrl | UnableToGeneratePublicUrl) {
            return null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The media type from the bytes, not the extension: an upload's temp file
     * has no extension, and a renamed file should not lie about itself.
     */
    private static function detectMime(string $path): string
    {
        $info = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
        if ($info !== false) {
            $mime = finfo_file($info, $path);
            finfo_close($info);
            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        }
        return 'application/octet-stream';
    }
}
