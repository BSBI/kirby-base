<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\FormBuilderIndexDefinition;
use BSBI\WebBase\helpers\FormSubmissionIndexDefinition;
use Kirby\Cms\App;
use BSBI\WebBase\Testing\KirbyContentBuilder;
use BSBI\WebBase\Testing\KirbyTestEnvironment;
use Kirby\Data\Json;
use PHPUnit\Framework\TestCase;

/**
 * Tests for FormBuilderIndexDefinition: panel-built form pages indexed by
 * form type and the "Report as" columns they use.
 */
final class FormBuilderIndexDefinitionTest extends TestCase
{
    private static App $app;

    public static function setUpBeforeClass(): void
    {
        self::$app = KirbyTestEnvironment::boot('kirby-base-form-builder-index-' . uniqid());
    }

    public function testNameAndTemplates(): void
    {
        $definition = new FormBuilderIndexDefinition();

        $this->assertSame('form_builders', $definition->getName());
        $this->assertSame(['form_builder'], $definition->getTemplates());
        $this->assertSame(['my_form'], (new FormBuilderIndexDefinition(['my_form']))->getTemplates());
        $this->assertArrayHasKey('form_type', $definition->getColumns());
        $this->assertArrayHasKey('report_columns', $definition->getColumns());
    }

    public function testUnlistedAndDraftFormsAreIndexedToo(): void
    {
        // Forms are usually unlisted pages or drafts; the default rule (listed
        // only) would leave most of them out.
        $site = self::$app->site();
        self::$app->impersonate('kirby');
        $listed = $site->createChild(['slug' => 'f-listed', 'template' => 'form_builder', 'draft' => false])->changeStatus('listed');
        $unlisted = $site->createChild(['slug' => 'f-unlisted', 'template' => 'form_builder', 'draft' => false]);
        $draft = $site->createChild(['slug' => 'f-draft', 'template' => 'form_builder']);
        $definition = new FormBuilderIndexDefinition();

        foreach ([$listed, $unlisted, $draft] as $page) {
            $this->assertTrue($definition->shouldIndex($page), $page->id());
        }
    }

    public function testOtherIndexesStillIndexListedPagesOnly(): void
    {
        self::$app->impersonate('kirby');
        $unlisted = self::$app->site()->createChild(['slug' => 's-unlisted', 'template' => 'form_submission', 'draft' => false]);

        $this->assertFalse((new FormSubmissionIndexDefinition())->shouldIndex($unlisted));
    }

    public function testRowHoldsTheNormalisedFormTypeAndReportAsColumns(): void
    {
        $page = (new KirbyContentBuilder())->page([
            'form_type'    => 'Event Feedback',
            'formSections' => Json::encode([[
                'id'      => 'aaaaaaaa-0000-4000-8000-000000000001',
                'type'    => 'form-section-inline',
                'content' => [
                    'title'      => 'About you',
                    'formFields' => Json::encode([
                        ['id' => 'bbbbbbbb-0000-4000-8000-000000000001', 'type' => 'form-textbox',
                            'content' => ['label' => 'Email', 'name' => 'email']],
                        ['id' => 'bbbbbbbb-0000-4000-8000-000000000002', 'type' => 'form-textbox',
                            'content' => ['label' => 'View', 'name' => 'view', 'reportAs' => 'overall']],
                        ['id' => 'bbbbbbbb-0000-4000-8000-000000000003', 'type' => 'form-textbox',
                            'content' => ['label' => 'Mood', 'reportAs' => 'Enjoyed']],
                    ]),
                ],
            ]]),
        ], 'my-form');

        $row = (new FormBuilderIndexDefinition())->rowFor($page);

        $this->assertSame('my-form', $row['page_id']);
        $this->assertSame('event_feedback', $row['form_type']);
        $this->assertSame('overall, Enjoyed', $row['report_columns']);
    }
}
