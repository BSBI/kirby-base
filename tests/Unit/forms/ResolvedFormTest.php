<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\forms;

use BSBI\WebBase\forms\about\AboutSubject;
use BSBI\WebBase\forms\ResolvedForm;
use BSBI\WebBase\forms\ResolvedFormField;
use BSBI\WebBase\forms\ResolvedFormSection;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ResolvedForm: a form opened from an about link leaves out the
 * questions the link answers.
 */
final class ResolvedFormTest extends TestCase
{
    public function testAboutASubjectTheFillFromQuestionsAreLeftOutAndEmptiedSectionsGo(): void
    {
        $groups = [
            new ResolvedFormSection('start', 'The event', [$this->field('event_name'), $this->field('event_date')], null, null),
            new ResolvedFormSection('more', 'More', [$this->field('event_name_2'), $this->field('liked')], null, null),
            $this->field('name'),
        ];
        $walk = new AboutSubject('page://walk', 'Kenfig walk', '2026-09-14', 'walk.sig');

        $form = new ResolvedForm($groups, null, false, 'token', '', $walk, ['event_name' => 'title', 'event_date' => 'date', 'event_name_2' => 'title']);

        $this->assertCount(2, $form->fieldGroups);
        $this->assertInstanceOf(ResolvedFormSection::class, $form->fieldGroups[0]);
        $this->assertSame(['liked'], array_map(static fn(ResolvedFormField $f): string => $f->name, $form->fieldGroups[0]->fields));
        $this->assertSame($walk, $form->about);
    }

    public function testAQuestionTheSubjectHasNoValueForIsStillAsked(): void
    {
        $groups = [$this->field('event_name'), $this->field('event_date'), $this->field('leader')];
        $undated = new AboutSubject('page://walk', 'Kenfig walk', '', 'walk.sig');

        $form = new ResolvedForm($groups, null, false, 'token', '', $undated, ['event_name' => 'title', 'event_date' => 'date', 'leader' => 'ledBy']);

        $this->assertSame(['event_date', 'leader'], array_map(static fn(ResolvedFormField $f): string => $f->name, $form->fieldGroups));
    }

    public function testWithoutASubjectEveryQuestionIsAsked(): void
    {
        $groups = [new ResolvedFormSection('start', 'The event', [$this->field('event_name')], null, null)];

        $form = new ResolvedForm($groups, null, false, 'token', '', null, ['event_name' => 'title']);

        $this->assertSame($groups, $form->fieldGroups);
        $this->assertNull($form->about);
    }

    private function field(string $name): ResolvedFormField
    {
        return new ResolvedFormField(type: 'textbox', name: $name, label: 'Label');
    }
}
