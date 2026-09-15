<?php

declare(strict_types=1);

namespace BSBI\WebBase\storage;

use Aws\S3\S3Client;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use League\Flysystem\Local\LocalFilesystemAdapter;

/**
 * Builds a DocumentStorage from a configuration array — the contents of a
 * site's gitignored documents.php:
 *
 *   ['driver' => 's3', 'bucket' => …, 'region' => …, 'key' => …, 'secret' => …, 'prefix' => '']
 *   ['driver' => 'local', 'root' => '/absolute/path']
 *   ['driver' => 'memory']
 *
 * Refuses loudly rather than falling back: a site that meant S3 and got a
 * local directory would be storing student documents in the wrong place.
 */
final readonly class DocumentStorageFactory
{
    /**
     * @param array<string, mixed> $config
     * @throws DocumentStorageException When the driver is unknown, a setting is missing, or a library is absent
     */
    public static function fromConfig(array $config): DocumentStorage
    {
        $driver = strtolower(self::setting($config, 'driver'));
        return match ($driver) {
            's3'     => self::s3($config),
            'local'  => self::local($config),
            'memory' => self::memory(),
            ''       => throw new DocumentStorageException('documents.php names no driver (s3, local or memory)'),
            default  => throw new DocumentStorageException('Unknown document storage driver "' . $driver . '"'),
        };
    }

    public static function memory(): DocumentStorage
    {
        self::assertClass(InMemoryFilesystemAdapter::class, 'league/flysystem-memory');
        return new FlysystemDocumentStorage(new Filesystem(new InMemoryFilesystemAdapter()));
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function local(array $config): DocumentStorage
    {
        $root = self::setting($config, 'root');
        if ($root === '') {
            throw new DocumentStorageException('The local document storage needs a root directory');
        }
        self::assertClass(LocalFilesystemAdapter::class, 'league/flysystem');
        return new FlysystemDocumentStorage(new Filesystem(new LocalFilesystemAdapter($root)));
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function s3(array $config): DocumentStorage
    {
        $s3 = [];
        foreach (['bucket', 'region', 'key', 'secret', 'prefix', 'endpoint'] as $setting) {
            $s3[$setting] = self::setting($config, $setting);
            if ($s3[$setting] === '' && !in_array($setting, ['prefix', 'endpoint'], true)) {
                throw new DocumentStorageException('The S3 document storage needs "' . $setting . '" in documents.php');
            }
        }
        self::assertClass(AwsS3V3Adapter::class, 'league/flysystem-aws-s3-v3');
        self::assertClass(S3Client::class, 'aws/aws-sdk-php');

        $client = new S3Client([
            'version'     => 'latest',
            'region'      => $s3['region'],
            'credentials' => ['key' => $s3['key'], 'secret' => $s3['secret']],
        ] + ($s3['endpoint'] !== '' ? ['endpoint' => $s3['endpoint'], 'use_path_style_endpoint' => true] : []));
        $adapter = new AwsS3V3Adapter($client, $s3['bucket'], trim($s3['prefix'], '/'));
        return new FlysystemDocumentStorage(new Filesystem($adapter));
    }

    /**
     * A setting as a trimmed string; anything that is not a scalar reads as absent.
     *
     * @param array<string, mixed> $config
     */
    private static function setting(array $config, string $key): string
    {
        $value = $config[$key] ?? null;
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private static function assertClass(string $class, string $package): void
    {
        if (!class_exists($class)) {
            throw new DocumentStorageException('Document storage needs the Composer package ' . $package . ' (' . $class . ' is not installed)');
        }
    }
}
