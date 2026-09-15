<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\storage;

use BSBI\WebBase\storage\DocumentRef;
use BSBI\WebBase\storage\DocumentStorageException;
use BSBI\WebBase\storage\DocumentStorageFactory;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The storage over the in-memory adapter: the same class the servers run over
 * S3, exercised without a bucket.
 */
final class FlysystemDocumentStorageTest extends TestCase
{
    private string $local;

    protected function setUp(): void
    {
        $this->local = sys_get_temp_dir() . '/kb-storage-' . uniqid() . '.png';
        // a 1×1 PNG, so the media type is detected from the bytes, not the name
        file_put_contents($this->local, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
    }

    protected function tearDown(): void
    {
        @unlink($this->local);
    }

    private function ref(string $name = 'photo.png', string $course = 'identiplant-2026'): DocumentRef
    {
        return new DocumentRef($course, 'unit-3', 'abc123', 'form-file', $name, new DateTimeImmutable('2026-06-25 14:03:02'));
    }

    public function testStoreReadSizeMimeAndExists(): void
    {
        $storage = DocumentStorageFactory::memory();

        $stored = $storage->store($this->local, $this->ref());

        $this->assertSame($this->ref()->key(), $stored->key);
        $this->assertSame('photo.png', $stored->filename);
        $this->assertSame(filesize($this->local), $stored->size);
        $this->assertSame('image/png', $stored->mime);
        $this->assertTrue($storage->exists($stored->key));
        $this->assertSame($stored->size, $storage->size($stored->key));
        $this->assertSame('image/png', $storage->mime($stored->key));
        $stream = $storage->readStream($stored->key);
        $this->assertSame(file_get_contents($this->local), stream_get_contents($stream));
        fclose($stream);
    }

    public function testWriteStoresBytesUnderAnExplicitKey(): void
    {
        $storage = DocumentStorageFactory::memory();

        $stored = $storage->write('documents/x/thumb.jpg', 'jpeg bytes', 'image/jpeg');

        $this->assertSame('documents/x/thumb.jpg', $stored->key);
        $this->assertSame(10, $stored->size);
        $this->assertTrue($storage->exists('documents/x/thumb.jpg'));
    }

    public function testListCopyAndDelete(): void
    {
        $storage = DocumentStorageFactory::memory();
        $a = $storage->store($this->local, $this->ref('a.png'));
        $b = $storage->store($this->local, $this->ref('b.png'));
        $storage->store($this->local, $this->ref('c.png', 'other-course'));

        $listed = iterator_to_array($storage->list(DocumentRef::coursePrefix('identiplant-2026')), false);
        sort($listed);
        $this->assertSame([$a->key, $b->key], $listed);

        $storage->copy($a->key, 'documents/copy.png');
        $this->assertTrue($storage->exists('documents/copy.png'));

        $storage->delete($a->key);
        $this->assertFalse($storage->exists($a->key));
        $storage->delete($a->key); // not an error the second time
    }

    public function testMissingObjectsThrowOnReadAndReturnFalseOnExists(): void
    {
        $storage = DocumentStorageFactory::memory();

        $this->assertFalse($storage->exists('documents/nope'));
        $this->expectException(DocumentStorageException::class);
        $storage->readStream('documents/nope');
    }

    public function testAnUnreadableLocalFileIsRefused(): void
    {
        $storage = DocumentStorageFactory::memory();

        $this->expectException(DocumentStorageException::class);
        $storage->store('/nonexistent/upload.tmp', $this->ref());
    }

    public function testTemporaryUrlIsNullWhereTheBackendCannotMakeOne(): void
    {
        $this->assertNull(DocumentStorageFactory::memory()->temporaryUrl('documents/x'));
    }
}
