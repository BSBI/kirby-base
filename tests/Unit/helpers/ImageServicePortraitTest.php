<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\ImageService;
use BSBI\WebBase\helpers\KirbyFieldReader;
use BSBI\WebBase\models\ImageSizes;
use BSBI\WebBase\models\ImageType;
use Kirby\Cms\App;
use Kirby\Cms\File;
use PHPUnit\Framework\TestCase;

/**
 * ImageType::PORTRAIT picks a site's `portrait` / `portrait-webp` srcset
 * presets, for upright images such as book covers (bsbi-web#747: eBook covers
 * at 2:3 rather than square).
 *
 * Uses a real raster image so thumb()/srcset() run for real; skipped when the
 * GD/WebP toolchain is unavailable.
 */
final class ImageServicePortraitTest extends TestCase
{
    private static App $kirby;
    private static ImageService $service;
    private static string $tmpDir;
    private static ?File $imageFile = null;

    public static function setUpBeforeClass(): void
    {
        if (!function_exists('imagepng') || !function_exists('imagewebp')) {
            return; // each test markTestSkipped()s below
        }

        self::$tmpDir = sys_get_temp_dir() . '/kirby-image-portrait-test-' . uniqid();
        $contentDir   = self::$tmpDir . '/content';
        $pageDir      = $contentDir . '/photos';
        mkdir($pageDir, 0777, true);

        file_put_contents($contentDir . '/site.txt', "Title: Test Site\n");
        file_put_contents($pageDir . '/photos.txt', "Title: Photos\n");

        // A real PNG large enough to feed the panel srcset variants.
        $im = imagecreatetruecolor(900, 675);
        imagepng($im, $pageDir . '/photo.png');
        imagedestroy($im);

        self::$kirby = new App([
            'roots'   => [
                'index'   => self::$tmpDir,
                'content' => $contentDir,
            ],
            'options' => [
                'thumbs' => [
                    'quality' => 80,
                    'srcsets' => [
                        'panel' => [
                            '400w' => ['width' => 400, 'height' => 300, 'crop' => true],
                        ],
                        'panel-webp' => [
                            '400w' => ['width' => 400, 'height' => 300, 'format' => 'webp', 'crop' => true],
                        ],
                        'portrait' => [
                            '1x' => ['width' => 200, 'height' => 300, 'crop' => true],
                            '2x' => ['width' => 400, 'height' => 600, 'crop' => true],
                        ],
                        'portrait-webp' => [
                            '1x' => ['width' => 200, 'height' => 300, 'format' => 'webp', 'crop' => true],
                            '2x' => ['width' => 400, 'height' => 600, 'format' => 'webp', 'crop' => true],
                        ],
                    ],
                ],
            ],
        ]);

        $fieldReader   = new KirbyFieldReader(self::$kirby, self::$kirby->site());
        self::$service = new ImageService($fieldReader);
        self::$imageFile = self::$kirby->page('photos')?->file('photo.png');
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$tmpDir) && is_dir(self::$tmpDir)) {
            exec('rm -rf ' . escapeshellarg(self::$tmpDir));
        }
    }

    private function requireImage(): File
    {
        if (self::$imageFile === null) {
            $this->markTestSkipped('GD/WebP toolchain unavailable for real thumbnail generation');
        }
        return self::$imageFile;
    }

    public function testPortraitUsesThePortraitPresets(): void
    {
        $image = self::$service->getImageFromFile($this->requireImage(), 200, 300, 80, ImageType::PORTRAIT);

        $this->assertTrue($image->isAvailable());
        $this->assertSame(200, $image->getWidth());
        $this->assertSame(300, $image->getHeight());
        // Upright crops from the portrait preset, not the panel fallback (400x300).
        $this->assertStringContainsString('200x300', $image->getSrcset());
        $this->assertStringContainsString('400x600', $image->getSrcset());
        $this->assertStringNotContainsString('400x300', $image->getSrcset());
        $this->assertStringContainsString('200x300', $image->getWebpSrcset());
    }

    public function testThePresetNameIsPortrait(): void
    {
        // ImageService looks presets up by the lowercased case value.
        $this->assertSame('portrait', strtolower(ImageType::PORTRAIT->value));
    }
}
