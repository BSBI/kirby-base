# Document storage

`BSBI\WebBase\storage` keeps uploaded documents — student submissions, marked
sheets, answer photos — somewhere other than the Kirby content tree: an S3
bucket on the servers, a local directory for development, memory for tests.
Callers see keys, streams and sizes, never a filesystem path or a public URL.

## The pieces

| Class | Role |
|---|---|
| `DocumentStorage` | the interface: `store()`, `write()`, `readStream()`, `exists()`, `size()`, `mime()`, `delete()`, `copy()`, `list()`, `temporaryUrl()` |
| `FlysystemDocumentStorage` | the one implementation, over any Flysystem filesystem; objects are written private |
| `DocumentStorageFactory` | builds one from a config array: `s3`, `local` or `memory`; refuses loudly rather than falling back |
| `DocumentRef` | the key layout, and the only place it lives: `documents/{course}/{unit}/{owner}/{kind}/{timestamp}-{safe name}` |
| `StoredDocument` | what a page record needs about an object: key, filename, size, media type |
| `StreamDelivery` | serves a stream of known size with HTTP Range support, in chunks, ending the request — Kirby's Response holds its body as a string, which a large document must avoid |

## Configuration

A consuming site keeps a gitignored `documents.php` beside its other per-server
config, returning one of:

```php
return ['driver' => 's3', 'bucket' => '…', 'region' => 'eu-west-2', 'key' => '…', 'secret' => '…', 'prefix' => ''];
return ['driver' => 'local', 'root' => __DIR__ . '/../../storage/documents'];
return ['driver' => 'memory'];
```

One bucket per environment: staging's credentials must not be able to read
live. The factory throws `DocumentStorageException` naming the missing setting
or package, so a server that meant S3 never quietly stores documents on disk.

## Dependencies

`league/flysystem` and, for S3, `league/flysystem-aws-s3-v3` (which brings
`aws/aws-sdk-php`); `league/flysystem-memory` for tests. They are `suggest`ed
here and required by the consuming site, the same way the certificate PDF
libraries are — see the README on why.
