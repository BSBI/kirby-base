<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\KirbyBaseHelper;
use BSBI\WebBase\models\BaseWebPage;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use PHPUnit\Framework\TestCase;

/**
 * handleTwoWayTagging() guards against re-entry with a static flag. Returning
 * early for a page with no tag fields used to leave the flag set, so every later
 * call in the same request skipped with "Already syncing" and tagged nothing.
 */
final class TwoWayTaggingReentryTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = sys_get_temp_dir() . '/kirby-base-two-way-tagging-' . uniqid();
        $files = [
            'content/1_places/default.txt'                => 'Title: Places',
            'content/1_places/1_scotland/country.txt'     => "Title: Scotland\n\n----\n\nUuid: scotland",
            'content/2_articles/default.txt'              => 'Title: Articles',
            'content/2_articles/1_plain/plain.txt'        => "Title: Plain\n\n----\n\nUuid: plain",
            'content/2_articles/2_tagged/article.txt'     =>
                "Title: Tagged\n\n----\n\nUuid: tagged\n\n----\n\nCountries: - page://scotland",
            // Kirby treats a page as 'default' unless its template file exists.
            'site/templates/country.php'                  => '',
            'site/templates/plain.php'                    => '',
            'site/templates/article.php'                  => '',
        ];
        foreach ($files as $path => $text) {
            $full = self::$root . '/' . $path;
            if (!is_dir(dirname($full))) {
                mkdir(dirname($full), 0777, true);
            }
            file_put_contents($full, $text);
        }

        new App([
            'roots' => [
                'index'   => self::$root,
                'content' => self::$root . '/content',
                'site'    => self::$root . '/site',
                'cache'   => self::$root . '/cache',
            ],
            'options' => ['tagMapping' => ['article' => 'articles', 'plain' => 'articles']],
            'blueprints' => [
                'pages/country' => ['fields' => ['articles' => ['type' => 'pages']]],
                'pages/plain'   => ['fields' => ['summary' => ['type' => 'text']]],
                'pages/article' => [
                    'sections' => [
                        'tags' => ['type' => 'fields', 'fields' => ['countries' => ['type' => 'pages', 'label' => 'Countries']]],
                    ],
                ],
            ],
        ]);
        App::instance()->impersonate('kirby');
    }

    public static function tearDownAfterClass(): void
    {
        restore_error_handler();
        restore_exception_handler();
    }

    public function testAPageWithoutTagFieldsDoesNotBlockLaterTagging(): void
    {
        $helper = $this->helper();
        $kirby = App::instance();

        $plain = $kirby->page('articles/plain');
        $this->assertInstanceOf(Page::class, $plain);
        $this->assertSame('', $helper->handleTwoWayTagging($plain));

        $tagged = $kirby->page('articles/tagged');
        $this->assertInstanceOf(Page::class, $tagged);
        $log = $helper->handleTwoWayTagging($tagged);

        $this->assertStringNotContainsString('Already syncing', $log);
        $this->assertSame(
            ['articles/tagged'],
            $kirby->page('places/scotland')?->articles()->toPages()->pluck('id')
        );
    }

    private function helper(): KirbyBaseHelper
    {
        return new class extends KirbyBaseHelper {
            function getBasicPage(): BaseWebPage
            {
                throw new \LogicException('not used');
            }

            function setBasicPage(Page $kirbyPage, BaseWebPage $currentPage): BaseWebPage
            {
                return $currentPage;
            }
        };
    }
}
