<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\storage;

use BSBI\WebBase\storage\DocumentStorage;
use BSBI\WebBase\storage\DocumentStorageException;
use BSBI\WebBase\storage\DocumentStorageFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The factory refuses loudly rather than falling back: a site that meant S3
 * and got a local directory would be storing student documents in the wrong
 * place.
 */
final class DocumentStorageFactoryTest extends TestCase
{
    public function testMemoryAndLocalDriversBuild(): void
    {
        $this->assertInstanceOf(DocumentStorage::class, DocumentStorageFactory::fromConfig(['driver' => 'memory']));
        $this->assertInstanceOf(DocumentStorage::class, DocumentStorageFactory::fromConfig(['driver' => 'local', 'root' => sys_get_temp_dir()]));
    }

    public function testLocalNeedsARoot(): void
    {
        $this->expectException(DocumentStorageException::class);
        DocumentStorageFactory::fromConfig(['driver' => 'local']);
    }

    public function testNoDriverIsRefusedWithTheFileNamed(): void
    {
        $this->expectException(DocumentStorageException::class);
        $this->expectExceptionMessageMatches('/documents\.php/');
        DocumentStorageFactory::fromConfig([]);
    }

    public function testAnUnknownDriverIsRefused(): void
    {
        $this->expectException(DocumentStorageException::class);
        $this->expectExceptionMessageMatches('/Unknown/');
        DocumentStorageFactory::fromConfig(['driver' => 'ftp']);
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: string}>
     */
    public static function incompleteS3(): array
    {
        $full = ['driver' => 's3', 'bucket' => 'b', 'region' => 'eu-west-2', 'key' => 'k', 'secret' => 's'];
        $cases = [];
        foreach (['bucket', 'region', 'key', 'secret'] as $missing) {
            $config = $full;
            $config[$missing] = '';
            $cases['missing ' . $missing] = [$config, $missing];
        }
        return $cases;
    }

    /**
     * @param array<string, string> $config
     */
    #[DataProvider('incompleteS3')]
    public function testS3RefusesEachMissingSetting(array $config, string $missing): void
    {
        $this->expectException(DocumentStorageException::class);
        $this->expectExceptionMessageMatches('/"' . $missing . '"/');
        DocumentStorageFactory::fromConfig($config);
    }

    public function testS3WithEverySettingBuildsWithoutTouchingTheNetwork(): void
    {
        $storage = DocumentStorageFactory::fromConfig([
            'driver' => 's3', 'bucket' => 'b', 'region' => 'eu-west-2', 'key' => 'k', 'secret' => 's', 'prefix' => '/live/',
        ]);

        $this->assertInstanceOf(DocumentStorage::class, $storage);
        // a pre-signed URL is made locally from the credentials: no request
        $url = $storage->temporaryUrl('documents/x.pdf', 60);
        $this->assertIsString($url);
        $this->assertStringContainsString('documents/x.pdf', $url);
        $this->assertStringContainsString('X-Amz-Signature', $url);
    }
}
