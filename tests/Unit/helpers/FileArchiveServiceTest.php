<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\FileArchiveService;
use Closure;
use Kirby\Cms\App;
use Kirby\Cms\File;
use Kirby\Exception\InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Tests for FileArchiveService (bsbi-web#570): File Archive files are addressed by
 * their permanent URL everywhere — `$file->url()` reports it, the `/files/<slug>` route
 * streams the file at it instead of redirecting to the hashed media URL, every archive
 * file gets a slug on upload, and slugs are validated on save.
 *
 * Boots one App per class with a real file on disk under the archive page, so the
 * streamed response can be checked byte for byte, plus a second page holding a file
 * with the same slug to prove the archive scope. The App is booted with the same
 * `file::url` component the plugin registers, so `$file->url()` and permalink
 * resolution are exercised through Kirby rather than by calling the service directly.
 */
final class FileArchiveServiceTest extends TestCase
{
    private const ARCHIVE_ID = 'file-archive';
    private const SLUG = 'Organising-and-Leading-BSBI-Meetings-pdf';
    private const PDF_BYTES = "%PDF-1.4\n%fake\n";

    private static App $kirby;
    private static FileArchiveService $service;
    private static string $tmpDir;

    public static function setUpBeforeClass(): void
    {
        self::$tmpDir = sys_get_temp_dir() . '/kirby-file-archive-service-test-' . uniqid();
        $contentDir   = self::$tmpDir . '/content';
        mkdir($contentDir . '/' . self::ARCHIVE_ID, 0777, true);
        mkdir($contentDir . '/other', 0777, true);
        file_put_contents($contentDir . '/' . self::ARCHIVE_ID . '/leaders-guidance.pdf', self::PDF_BYTES);
        file_put_contents($contentDir . '/' . self::ARCHIVE_ID . '/no-slug.pdf', self::PDF_BYTES);
        file_put_contents($contentDir . '/other/elsewhere.pdf', self::PDF_BYTES);

        self::$kirby = new App([
            'roots' => [
                'index'   => self::$tmpDir,
                'content' => $contentDir,
            ],
            'urls' => ['index' => 'https://example.test'],
            'options' => [
                'fileArchive' => ['pageId' => self::ARCHIVE_ID],
            ],
            // The same wiring as kirby-base/index.php registers.
            'components' => [
                'file::url' => function (App $kirby, File $file): string {
                    $native = $kirby->nativeComponent('file::url');
                    return FileArchiveService::fromKirby($kirby)
                        ->resolveUrl($file, $native instanceof Closure ? $native : null);
                },
            ],
            'site' => [
                'children' => [
                    [
                        'slug'     => self::ARCHIVE_ID,
                        'template' => 'file_archive',
                        'files'    => [
                            [
                                'filename' => 'leaders-guidance.pdf',
                                'content'  => [
                                    'template'     => 'file_archive_item',
                                    'permanentUrl' => self::SLUG,
                                    'uuid'         => 'leadersguidance1',
                                ],
                            ],
                            [
                                'filename' => 'no-slug.pdf',
                                'content'  => ['template' => 'file_archive_item'],
                            ],
                        ],
                    ],
                    [
                        'slug'  => 'other',
                        'files' => [
                            [
                                'filename' => 'elsewhere.pdf',
                                // Same slug outside the archive: must never resolve.
                                'content'  => ['permanentUrl' => self::SLUG],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        self::$service = FileArchiveService::fromKirby(self::$kirby);
    }

    public static function tearDownAfterClass(): void
    {
        $root = self::$tmpDir;
        foreach (['/content/' . self::ARCHIVE_ID . '/leaders-guidance.pdf', '/content/' . self::ARCHIVE_ID . '/no-slug.pdf', '/content/other/elsewhere.pdf'] as $f) {
            @unlink($root . $f);
        }
        @rmdir($root . '/content/' . self::ARCHIVE_ID);
        @rmdir($root . '/content/other');
        @rmdir($root . '/content');
        @rmdir($root);
    }

    private function archiveFile(string $filename = 'leaders-guidance.pdf'): File
    {
        $file = self::$kirby->page(self::ARCHIVE_ID)?->file($filename);
        self::assertInstanceOf(File::class, $file);
        return $file;
    }

    // -------------------------------------------------------------------------
    // Configuration
    // -------------------------------------------------------------------------

    public function testFromKirbyReadsTheArchivePageIdOption(): void
    {
        $this->assertSame(self::ARCHIVE_ID, self::$service->archivePageId());
    }

    public function testDefaultArchivePageIdIsFileArchive(): void
    {
        $this->assertSame('file-archive', FileArchiveService::DEFAULT_PAGE_ID);
    }

    // -------------------------------------------------------------------------
    // permanentUrl / resolveUrl
    // -------------------------------------------------------------------------

    public function testPermanentUrlForArchiveFileWithSlug(): void
    {
        $this->assertSame(
            'https://example.test/files/' . self::SLUG,
            self::$service->permanentUrl($this->archiveFile())
        );
    }

    public function testPermanentUrlIsNullWithoutSlug(): void
    {
        $this->assertNull(self::$service->permanentUrl($this->archiveFile('no-slug.pdf')));
    }

    public function testPermanentUrlIsNullOutsideTheArchive(): void
    {
        $file = self::$kirby->page('other')?->file('elsewhere.pdf');
        self::assertInstanceOf(File::class, $file);
        $this->assertNull(self::$service->permanentUrl($file));
    }

    public function testResolveUrlFallsBackToNativeUrl(): void
    {
        $file   = $this->archiveFile('no-slug.pdf');
        $native = fn (App $kirby, File $f): string => 'native:' . $f->filename();
        $this->assertSame('native:no-slug.pdf', self::$service->resolveUrl($file, $native));
    }

    public function testResolveUrlFallsBackToMediaUrlWhenNoNativeComponent(): void
    {
        $file = $this->archiveFile('no-slug.pdf');
        $this->assertSame($file->mediaUrl(), self::$service->resolveUrl($file, null));
    }

    // -------------------------------------------------------------------------
    // Through Kirby: $file->url() and permalink resolution use the component
    // -------------------------------------------------------------------------

    public function testFileUrlReportsThePermanentUrl(): void
    {
        $this->assertSame('https://example.test/files/' . self::SLUG, $this->archiveFile()->url());
    }

    public function testFileUrlWithoutSlugStaysOnTheMediaUrl(): void
    {
        $this->assertStringContainsString('/media/pages/', $this->archiveFile('no-slug.pdf')->url());
    }

    public function testFileOutsideTheArchiveKeepsTheMediaUrl(): void
    {
        $file = self::$kirby->page('other')?->file('elsewhere.pdf');
        self::assertInstanceOf(File::class, $file);
        $this->assertStringContainsString('/media/pages/', $file->url());
    }

    public function testWriterPermalinkResolvesToThePermanentUrl(): void
    {
        $html = '<a href="/@/file/leadersguidance1">Guidance</a>';
        $resolved = self::$kirby->site()->content()->get('x')
            ->value($html)->permalinksToUrls()->toString();
        $this->assertSame('<a href="https://example.test/files/' . self::SLUG . '">Guidance</a>', $resolved);
    }

    // -------------------------------------------------------------------------
    // findBySlug
    // -------------------------------------------------------------------------

    public function testFindBySlugReturnsTheArchiveFile(): void
    {
        $this->assertSame('leaders-guidance.pdf', self::$service->findBySlug(self::SLUG)?->filename());
    }

    public function testFindBySlugIsExactOnCase(): void
    {
        $this->assertNull(self::$service->findBySlug(strtolower(self::SLUG)));
    }

    public function testFindBySlugIgnoresFilesOutsideTheArchive(): void
    {
        $this->assertNull(self::$service->findBySlug('nothing-here'));
        // The 'other' page carries the same slug; only the archive counts.
        $this->assertSame(self::ARCHIVE_ID, self::$service->findBySlug(self::SLUG)?->parent()?->id());
    }

    // -------------------------------------------------------------------------
    // Streaming response
    // -------------------------------------------------------------------------

    public function testRespondStreamsTheFileInline(): void
    {
        $response = self::$service->respond(self::SLUG);
        self::assertNotNull($response);

        $this->assertSame(200, $response->code());
        $this->assertSame('application/pdf', $response->type());
        $this->assertSame(self::PDF_BYTES, $response->body());

        $headers = $response->headers();
        $this->assertSame('inline; filename="leaders-guidance.pdf"', $headers['Content-Disposition']);
        $this->assertSame('public, max-age=3600', $headers['Cache-Control']);
        $this->assertMatchesRegularExpression('/^\w{3}, \d{2} \w{3} \d{4} \d{2}:\d{2}:\d{2} GMT$/', $headers['Last-Modified']);
    }

    public function testRespondReturnsNotModifiedWhenUnchangedSince(): void
    {
        $lastModified = self::$service->respond(self::SLUG)?->headers()['Last-Modified'] ?? '';
        $response = self::$service->respond(self::SLUG, $lastModified);
        self::assertNotNull($response);
        $this->assertSame(304, $response->code());
        $this->assertSame('', $response->body());
    }

    public function testRespondStreamsWhenModifiedSince(): void
    {
        $response = self::$service->respond(self::SLUG, 'Thu, 01 Jan 2015 00:00:00 GMT');
        self::assertNotNull($response);
        $this->assertSame(200, $response->code());
    }

    public function testRespondIsNullForUnknownSlug(): void
    {
        $this->assertNull(self::$service->respond('no-such-file'));
    }

    // -------------------------------------------------------------------------
    // Slugs on upload and on save
    // -------------------------------------------------------------------------

    public function testDefaultSlugIsTheFilename(): void
    {
        $this->assertSame('no-slug.pdf', self::$service->defaultSlug($this->archiveFile('no-slug.pdf')));
    }

    public function testValidSlugPasses(): void
    {
        self::$service->validateSlug('Annual-Report_2025.v2.pdf', $this->archiveFile('no-slug.pdf'));
        $this->addToAssertionCount(1);
    }

    public function testSlugWithSpacesIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/spaces/i');
        self::$service->validateSlug('annual report.pdf', $this->archiveFile('no-slug.pdf'));
    }

    public function testSlugWithSlashIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        self::$service->validateSlug('reports/annual.pdf', $this->archiveFile('no-slug.pdf'));
    }

    public function testSlugUsedByAnotherArchiveFileIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/leaders-guidance\.pdf/');
        self::$service->validateSlug(self::SLUG, $this->archiveFile('no-slug.pdf'));
    }

    public function testAFileMayKeepItsOwnSlug(): void
    {
        self::$service->validateSlug(self::SLUG, $this->archiveFile());
        $this->addToAssertionCount(1);
    }

    public function testEmptySlugIsAllowedOnSave(): void
    {
        self::$service->validateSlug('', $this->archiveFile('no-slug.pdf'));
        $this->addToAssertionCount(1);
    }

    public function testSlugFromUpdateValuesIsReadCaseInsensitively(): void
    {
        $this->assertSame('a.pdf', FileArchiveService::slugFromValues(['permanenturl' => 'a.pdf']));
        $this->assertSame('b.pdf', FileArchiveService::slugFromValues(['permanentUrl' => ' b.pdf ']));
        $this->assertNull(FileArchiveService::slugFromValues(['caption' => 'x']));
    }
}
