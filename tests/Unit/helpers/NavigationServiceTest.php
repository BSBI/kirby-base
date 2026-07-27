<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\ImageService;
use BSBI\WebBase\helpers\KirbyFieldReader;
use BSBI\WebBase\helpers\NavigationService;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use PHPUnit\Framework\TestCase;

/**
 * Tests for NavigationService::getWebPageLink.
 *
 * Focuses on resilience: a panel image that cannot be resolved (e.g. a page
 * referencing an image-bank file that does not exist in the local content
 * tree) must degrade to a link without an image, not break the page.
 */
final class NavigationServiceTest extends TestCase
{
    private static App $kirby;
    private static NavigationService $service;
    private static string $tmpDir;

    public static function setUpBeforeClass(): void
    {
        self::$tmpDir = sys_get_temp_dir() . '/kirby-navigation-service-test';
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

        $fieldReader  = new KirbyFieldReader(self::$kirby, self::$kirby->site());
        self::$service = new NavigationService(
            $fieldReader,
            new ImageService($fieldReader),
            self::$kirby->site()
        );
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

    public function testWebPageLinkSkipsPanelImageWhenFileCannotBeResolved(): void
    {
        // panelImage references a file missing from the content tree
        $page = $this->makePage([
            'panelcontent' => 'A description',
            'panelimage'   => 'file://no-such-file',
        ]);

        $link = self::$service->getWebPageLink($page, false);

        $this->assertFalse($link->hasImage(), 'an unresolvable panel image should be skipped, not fatal');
        $this->assertSame('A description', $link->getLinkDescription());
    }

    public function testWebPageLinkResolvesWithoutAnyPanelImage(): void
    {
        $page = $this->makePage(['panelcontent' => 'No image here']);

        $link = self::$service->getWebPageLink($page, false);

        $this->assertFalse($link->hasImage());
        $this->assertSame('No image here', $link->getLinkDescription());
    }
}
