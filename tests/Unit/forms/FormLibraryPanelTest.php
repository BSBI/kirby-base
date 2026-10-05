<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\forms;

use BSBI\WebBase\forms\FormLibraryPanel;
use BSBI\WebBase\Testing\KirbyTestEnvironment;
use Kirby\Cms\App;
use PHPUnit\Framework\TestCase;

/**
 * Tests for FormLibraryPanel: the "Form library" side-menu entry.
 */
final class FormLibraryPanelTest extends TestCase
{
    private static App $app;

    public static function setUpBeforeClass(): void
    {
        self::$app = KirbyTestEnvironment::boot('kirby-base-form-library-panel-' . uniqid())->clone([
            'blueprints' => [
                'pages/form_library' => dirname(__DIR__, 3) . '/blueprints/pages/form_library.yml',
            ],
        ]);
    }

    public function testMenuIsShownOnlyToTheConfiguredRoles(): void
    {
        $this->assertTrue(FormLibraryPanel::isAllowed('admin', ['admin', 'editor']));
        $this->assertTrue(FormLibraryPanel::isAllowed('editor', ['admin', 'editor']));
        $this->assertFalse(FormLibraryPanel::isAllowed('vice_county', ['admin', 'editor']));
        $this->assertFalse(FormLibraryPanel::isAllowed(null, ['admin', 'editor']));
        $this->assertFalse(FormLibraryPanel::isAllowed('editor', []));
    }

    public function testCurrentWhileInsideTheLibrary(): void
    {
        $this->assertTrue(FormLibraryPanel::isCurrentPath('panel/pages/form-library', 'panel'));
        $this->assertTrue(FormLibraryPanel::isCurrentPath('panel/pages/form-library+contact', 'panel'));
        $this->assertTrue(FormLibraryPanel::isCurrentPath('admin/pages/form-library', 'admin'));
        $this->assertFalse(FormLibraryPanel::isCurrentPath('panel/pages/form-library-old', 'panel'));
        $this->assertFalse(FormLibraryPanel::isCurrentPath('panel/pages/events', 'panel'));
        $this->assertFalse(FormLibraryPanel::isCurrentPath('panel/site', 'panel'));
    }

    public function testEnsureLibraryCreatesTheUnlistedPageOnce(): void
    {
        $this->assertNull(self::$app->site()->findPageOrDraft(FormLibraryPanel::SLUG));

        $created = FormLibraryPanel::ensureLibrary(self::$app);
        $again = FormLibraryPanel::ensureLibrary(self::$app);

        $this->assertSame('form-library', $created->id());
        $this->assertSame('form_library', $created->intendedTemplate()->name());
        $this->assertSame('Form library', $created->blueprint()->title(), 'the real blueprint is in force');
        $this->assertTrue($created->isUnlisted());
        $this->assertSame($created->id(), $again->id());
        $this->assertCount(1, self::$app->site()->childrenAndDrafts());
    }
}
