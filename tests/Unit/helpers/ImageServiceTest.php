<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\ImageService;
use BSBI\WebBase\helpers\KirbyFieldReader;
use BSBI\WebBase\helpers\KirbyRetrievalException;
use BSBI\WebBase\models\Image;
use Kirby\Cms\App;
use Kirby\Cms\File;
use Kirby\Cms\Page;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ImageService::getSvgImage and getSvgImageFromFile.
 *
 * getSvgImageFromFile is tested directly with File::factory objects, avoiding
 * the need to mock KirbyFieldReader (which is final).
 *
 * getSvgImage null/exception paths are tested via a real KirbyFieldReader
 * against pages whose file fields cannot be resolved.
 */
final class ImageServiceTest extends TestCase
{
    private static App $kirby;
    private static KirbyFieldReader $fieldReader;
    private static ImageService $service;
    private static string $tmpDir;

    public static function setUpBeforeClass(): void
    {
        self::$tmpDir = sys_get_temp_dir() . '/kirby-image-service-test';
        $contentDir   = self::$tmpDir . '/content';

        if (!is_dir($contentDir)) {
            mkdir($contentDir, 0777, true);
        }

        file_put_contents($contentDir . '/site.txt', "Title: Test Site\n");

        self::$kirby = new App([
            'roots' => [
                'index'   => self::$tmpDir,
                'content' => $contentDir,
            ],
        ]);

        self::$fieldReader = new KirbyFieldReader(self::$kirby, self::$kirby->site());
        self::$service     = new ImageService(self::$fieldReader);
    }

    public static function tearDownAfterClass(): void
    {
        @unlink(self::$tmpDir . '/content/site.txt');
    }

    private function makePage(array $content = []): Page
    {
        return Page::factory([
            'slug'    => 'test-' . uniqid(),
            'content' => $content,
        ]);
    }

    private function makeSvgFile(Page $page, string $alt = ''): File
    {
        $content = $alt !== '' ? ['alt' => $alt] : [];
        return File::factory([
            'filename' => 'logo.svg',
            'parent'   => $page,
            'content'  => $content,
        ]);
    }

    // -------------------------------------------------------------------------
    // getSvgImageFromFile — happy path
    // -------------------------------------------------------------------------

    public function testGetSvgImageFromFileReturnsSrcFromFileUrl(): void
    {
        $page  = $this->makePage();
        $file  = $this->makeSvgFile($page);
        $image = self::$service->getSvgImageFromFile($file);

        $this->assertInstanceOf(Image::class, $image);
        $this->assertSame($file->url(), $image->getSrc());
    }

    public function testGetSvgImageFromFileHasNoSrcset(): void
    {
        $file  = $this->makeSvgFile($this->makePage());
        $image = self::$service->getSvgImageFromFile($file);

        $this->assertSame('', $image->getSrcset());
        $this->assertSame('', $image->getWebpSrcset());
    }

    public function testGetSvgImageFromFileSetsAlt(): void
    {
        $file  = $this->makeSvgFile($this->makePage(), 'Course logo');
        $image = self::$service->getSvgImageFromFile($file);

        $this->assertSame('Course logo', $image->getAlt());
    }

    public function testGetSvgImageFromFileHasEmptyAltWhenNoneSet(): void
    {
        $file  = $this->makeSvgFile($this->makePage());
        $image = self::$service->getSvgImageFromFile($file);

        $this->assertSame('', $image->getAlt());
    }

    public function testGetSvgImageFromFileSetsClass(): void
    {
        $file  = $this->makeSvgFile($this->makePage());
        $image = self::$service->getSvgImageFromFile($file, 'img-fluid w-100');

        $this->assertSame('img-fluid w-100', $image->getClass());
    }

    public function testGetSvgImageFromFileDefaultClassIsEmpty(): void
    {
        $file  = $this->makeSvgFile($this->makePage());
        $image = self::$service->getSvgImageFromFile($file);

        $this->assertFalse($image->hasClass());
    }

    public function testGetSvgImageFromFileIsAvailable(): void
    {
        $file  = $this->makeSvgFile($this->makePage());
        $image = self::$service->getSvgImageFromFile($file);

        $this->assertTrue($image->isAvailable());
        $this->assertFalse($image->hasErrors());
    }

    // -------------------------------------------------------------------------
    // getSvgImage — null and exception paths via real KirbyFieldReader
    // -------------------------------------------------------------------------

    public function testGetSvgImageReturnsErrorImageWhenFileIsNull(): void
    {
        // Field exists but references a file that cannot be resolved → toFile() returns null
        $page  = $this->makePage(['logo' => 'nonexistent.svg']);
        $image = self::$service->getSvgImage($page, 'logo');

        $this->assertFalse($image->isAvailable());
        $this->assertTrue($image->hasErrors());
    }

    public function testGetSvgImageThrowsWhenFieldIsMissing(): void
    {
        $page = $this->makePage([]);

        $this->expectException(KirbyRetrievalException::class);
        self::$service->getSvgImage($page, 'missingField');
    }

    // -------------------------------------------------------------------------
    // getImage — unresolvable file reference must raise the domain exception,
    // not a TypeError (regression: a page referencing an image-bank file that
    // does not exist in the local content tree took the whole page down)
    // -------------------------------------------------------------------------

    public function testGetImageThrowsKirbyRetrievalExceptionWhenFileCannotBeResolved(): void
    {
        // Field exists but references a file that cannot be resolved → toFile() returns null
        $page = $this->makePage(['panelimage' => 'file://no-such-file']);

        $this->expectException(KirbyRetrievalException::class);
        self::$service->getImage($page, 'panelImage', 400, 300);
    }
}
