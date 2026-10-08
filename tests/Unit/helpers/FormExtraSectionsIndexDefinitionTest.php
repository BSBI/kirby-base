<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\FormExtraSectionsIndexDefinition;
use BSBI\WebBase\Testing\KirbyTestEnvironment;
use Kirby\Cms\App;
use Kirby\Data\Json;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the index of library sections used as extra sections on
 * hand-written forms.
 */
final class FormExtraSectionsIndexDefinitionTest extends TestCase
{
    private static App $app;

    public static function setUpBeforeClass(): void
    {
        self::$app = KirbyTestEnvironment::boot('kirby-base-form-extra-sections-index-' . uniqid(), [
            'forms.sectionsFields' => ['form_event_feedback' => 'extraSections'],
        ]);
    }

    public function testNameTemplatesAndColumns(): void
    {
        $definition = new FormExtraSectionsIndexDefinition(['form_event_feedback']);

        $this->assertSame('form_extra_sections', $definition->getName());
        $this->assertSame('formExtraSections', $definition->getCollectionName());
        $this->assertSame(['form_event_feedback'], $definition->getTemplates());
        $this->assertSame(['section_ids'], array_keys($definition->getColumns()));
    }

    public function testUnlistedAndDraftFormsAreIndexed(): void
    {
        self::$app->impersonate('kirby');
        $unlisted = self::$app->site()->createChild(['slug' => 'fb-unlisted', 'template' => 'form_event_feedback', 'draft' => false]);
        $draft = self::$app->site()->createChild(['slug' => 'fb-draft', 'template' => 'form_event_feedback']);
        $definition = new FormExtraSectionsIndexDefinition(['form_event_feedback']);

        $this->assertTrue($definition->shouldIndex($unlisted));
        $this->assertTrue($definition->shouldIndex($draft));
    }

    public function testRowHoldsTheLibrarySectionsTheExtraSectionsUse(): void
    {
        self::$app->impersonate('kirby');
        $section = self::$app->site()->createChild([
            'slug'     => 'diet-section',
            'template' => 'form_section',
            'draft'    => false,
            'content'  => [
                'legend'     => 'Diet',
                'formFields' => Json::encode([
                    ['id' => 'cccccccc-0000-4000-8000-000000000001', 'type' => 'form-textarea',
                        'content' => ['label' => 'Dietary needs', 'name' => 'diet']],
                ]),
            ],
        ]);
        $page = self::$app->site()->createChild([
            'slug'     => 'fb-with-extras',
            'template' => 'form_event_feedback',
            'draft'    => false,
            'content'  => [
                // A formSections field on this template is not its sections field.
                'formSections'  => Json::encode([]),
                'extraSections' => Json::encode([[
                    'id'      => 'dddddddd-0000-4000-8000-000000000001',
                    'type'    => 'form-section-ref',
                    'content' => ['section' => '- ' . $section->id()],
                ]]),
            ],
        ]);

        $row = (new FormExtraSectionsIndexDefinition(['form_event_feedback']))->rowFor($page);

        $this->assertSame(['page_id' => 'fb-with-extras', 'section_ids' => 'diet-section'], $row);
    }
}
