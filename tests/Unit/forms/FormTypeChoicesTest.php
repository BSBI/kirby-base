<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\forms;

use BSBI\WebBase\forms\FormBuilderOptions;
use BSBI\WebBase\forms\FormLibraryPanel;
use BSBI\WebBase\Testing\KirbyTestEnvironment;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Data\Yaml;
use PHPUnit\Framework\TestCase;

/**
 * Tests for FormBuilderOptions::formTypeChoices(): the form type select reads
 * the Form library's Form types list.
 */
final class FormTypeChoicesTest extends TestCase
{
    private static App $app;

    public static function setUpBeforeClass(): void
    {
        self::$app = KirbyTestEnvironment::boot('kirby-base-form-type-choices-' . uniqid())->clone([
            'blueprints' => [
                'pages/form_library' => dirname(__DIR__, 3) . '/blueprints/pages/form_library.yml',
            ],
        ]);
    }

    public function testFormChoicesAreOnlyForTheLibraryRoles(): void
    {
        // Nobody is logged in here; other panel roles get the same empty list.
        $this->assertNull(self::$app->user());
        $this->assertSame([], FormBuilderOptions::formBuilderChoices(self::$app));
    }

    public function testChoicesComeFromTheLibraryList(): void
    {
        $library = FormLibraryPanel::ensureLibrary(self::$app);
        $library = self::$app->impersonate('kirby', fn() => $library->update([
            'formTypes' => Yaml::encode([
                ['name' => 'Volunteer survey', 'description' => ''],
                ['name' => 'Event feedback', 'description' => 'After any event'],
            ]),
        ]));
        $this->assertInstanceOf(Page::class, $library);

        $this->assertSame(
            ['event_feedback' => 'Event feedback', 'volunteer_survey' => 'Volunteer survey'],
            FormBuilderOptions::formTypeChoices(self::$app)
        );
    }
}
