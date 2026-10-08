<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\forms;

use BSBI\WebBase\forms\FormLibraryPanel;
use BSBI\WebBase\Testing\KirbyTestEnvironment;
use Kirby\Cms\App;
use Kirby\Exception\PermissionException;
use Kirby\Http\Route;
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

    public function testAreaClosuresCanBeBoundByThePanel(): void
    {
        // The panel binds view actions (and may bind other area callbacks) to
        // its own objects, which a static closure refuses at runtime.
        $area = FormLibraryPanel::area(self::$app);
        $closures = [$area['menu'], $area['current'], $area['views'][0]['action']];

        foreach ($closures as $closure) {
            $this->assertInstanceOf(\Closure::class, $closure);
            $this->assertFalse((new \ReflectionFunction($closure))->isStatic());
        }
    }

    public function testTheViewActionStillOpensTheLibraryWhenThePanelRebindsIt(): void
    {
        // The panel runs a view action with Closure::call($route), which also
        // moves its class scope to Route: a `self::` call inside it would hit
        // Route::__call and quietly return null (a 404 in the panel). With
        // nobody logged in, reaching open() shows as its permission refusal.
        $action = FormLibraryPanel::area(self::$app)['views'][0]['action'];
        $this->assertInstanceOf(\Closure::class, $action);

        $this->expectException(PermissionException::class);
        $action->call(new Route('form-library', 'GET', static fn() => null));
    }

    public function testTheSideMenuEntryIsCalledForms(): void
    {
        $this->assertSame('Forms', FormLibraryPanel::area(self::$app)['label']);
    }

    public function testEnsureLibraryCreatesTheUnlistedPageOnce(): void
    {
        $this->assertNull(self::$app->site()->findPageOrDraft(FormLibraryPanel::SLUG));

        $created = FormLibraryPanel::ensureLibrary(self::$app);
        $again = FormLibraryPanel::ensureLibrary(self::$app);

        $this->assertSame('form-library', $created->id());
        $this->assertSame('form_library', $created->intendedTemplate()->name());
        $this->assertSame('Forms', $created->blueprint()->title(), 'the real blueprint is in force');
        $this->assertSame('Forms', $created->title()->value());
        $this->assertTrue($created->isUnlisted());
        $this->assertSame($created->id(), $again->id());
        $this->assertCount(1, self::$app->site()->childrenAndDrafts());
    }

    public function testALibraryStillCalledFormLibraryIsRenamedFormsAndOtherTitlesKept(): void
    {
        $library = FormLibraryPanel::ensureLibrary(self::$app);
        self::$app->impersonate('kirby', static fn() => $library->changeTitle('Form library'));

        $this->assertSame('Forms', FormLibraryPanel::ensureLibrary(self::$app)->title()->value());

        $renamed = self::$app->impersonate('kirby', static fn() => FormLibraryPanel::ensureLibrary(self::$app)->changeTitle('Our forms'));
        $this->assertInstanceOf(\Kirby\Cms\Page::class, $renamed);
        $this->assertSame('Our forms', FormLibraryPanel::ensureLibrary(self::$app)->title()->value());
    }
}
