<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\forms;

use BSBI\WebBase\forms\FormBuilderOptions;
use BSBI\WebBase\Testing\KirbyTestEnvironment;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the template => sections-field map (`forms.sectionsFields`).
 */
final class FormSectionsFieldsTest extends TestCase
{
    private static App $kirby;

    public static function setUpBeforeClass(): void
    {
        self::$kirby = KirbyTestEnvironment::boot('kirby-base-sections-fields-' . uniqid(), [
            'forms.builderTemplates' => ['form_builder', 'survey'],
            'forms.sectionsFields'   => ['form_event_feedback' => 'extraSections', 'bad' => 3],
            'forms.reservedKeys'     => static fn(Page $page): ?array
                => $page->intendedTemplate()->name() === 'form_event_feedback' ? ['email', 7, 'name'] : null,
        ]);
    }

    public function testByDefaultOnlyBuilderTemplatesHaveSections(): void
    {
        $this->assertSame(['form_builder' => 'formSections'], FormBuilderOptions::sectionsFieldsFrom(['form_builder'], null));
    }

    public function testInvalidEntriesAndBuilderTemplatesAreIgnored(): void
    {
        $this->assertSame(
            ['form_builder' => 'formSections', 'agm' => 'extraSections'],
            FormBuilderOptions::sectionsFieldsFrom(['form_builder'], [
                'form_builder' => 'extraSections',
                'agm'          => 'extraSections',
                'blank'        => '',
                3              => 'x',
            ])
        );
    }

    public function testConfiguredTemplatesAreAddedAndBuilderTemplatesKept(): void
    {
        $this->assertSame([
            'form_builder'        => 'formSections',
            'survey'              => 'formSections',
            'form_event_feedback' => 'extraSections',
        ], FormBuilderOptions::sectionsFields(self::$kirby));
        $this->assertSame(['form_event_feedback'], FormBuilderOptions::extraSectionsTemplates(self::$kirby));
    }

    public function testSectionsFieldForAPage(): void
    {
        $this->assertSame('extraSections', FormBuilderOptions::sectionsFieldFor($this->page('form_event_feedback')));
        $this->assertSame('formSections', FormBuilderOptions::sectionsFieldFor($this->page('survey')));
        $this->assertSame('formSections', FormBuilderOptions::sectionsFieldFor($this->page('default')));
    }

    public function testReservedKeysComeFromTheOptionForThePage(): void
    {
        $this->assertSame(['email', 'name'], FormBuilderOptions::reservedKeysFor($this->page('form_event_feedback')));
        $this->assertSame([], FormBuilderOptions::reservedKeysFor($this->page('form_builder')));
    }

    private function page(string $template): Page
    {
        return Page::factory(['slug' => 'p-' . uniqid(), 'template' => $template, 'content' => []]);
    }
}
