<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\forms;

use BSBI\WebBase\forms\RequiredFields;
use BSBI\WebBase\forms\ResolvedFormField;
use BSBI\WebBase\forms\ResolvedFormSection;
use PHPUnit\Framework\TestCase;

/**
 * Tests for RequiredFields: server-side required checks that skip sections a
 * condition hides.
 */
final class RequiredFieldsTest extends TestCase
{
    public function testABlankRequiredLooseFieldIsMissing(): void
    {
        $groups = [$this->field('name', required: true), $this->field('note')];

        $this->assertSame(['Name label'], RequiredFields::missing($groups, ['name' => '  ', 'note' => '']));
        $this->assertSame([], RequiredFields::missing($groups, ['name' => 'Ann']));
    }

    public function testARequiredFieldInAnUnconditionalSectionIsChecked(): void
    {
        $groups = [$this->section([$this->field('email', required: true)])];

        $this->assertSame(['Email label'], RequiredFields::missing($groups, []));
    }

    public function testARequiredFieldInAHiddenSectionIsNotMissing(): void
    {
        $groups = [
            $this->field('contact_by'),
            $this->section([$this->field('phone', required: true)], 'contact_by', 'Phone'),
        ];

        $this->assertSame([], RequiredFields::missing($groups, ['contact_by' => 'Email']));
        $this->assertSame([], RequiredFields::missing($groups, []));
    }

    public function testARequiredFieldInAShownSectionIsMissing(): void
    {
        $groups = [
            $this->field('contact_by'),
            $this->section([$this->field('phone', required: true)], 'contact_by', 'Phone'),
        ];

        $this->assertSame(['Phone label'], RequiredFields::missing($groups, ['contact_by' => 'Phone', 'phone' => '']));
        $this->assertSame([], RequiredFields::missing($groups, ['contact_by' => 'Phone', 'phone' => '0123']));
    }

    public function testAnEmptyCheckboxGroupOrRatingMatrixIsMissing(): void
    {
        $groups = [
            $this->field('topics', required: true, type: 'checkbox-group'),
            $this->field('ratings', required: true, type: 'rating-matrix'),
        ];

        $this->assertSame(['Topics label', 'Ratings label'], RequiredFields::missing($groups, ['topics' => []]));
        $this->assertSame([], RequiredFields::missing($groups, ['topics' => ['A'], 'ratings' => ['row' => '3']]));
    }

    private function field(string $name, bool $required = false, string $type = 'textbox'): ResolvedFormField
    {
        return new ResolvedFormField(type: $type, name: $name, label: ucfirst($name) . ' label', required: $required);
    }

    /**
     * @param list<ResolvedFormField> $fields
     */
    private function section(array $fields, ?string $conditionField = null, ?string $conditionValue = null): ResolvedFormSection
    {
        return new ResolvedFormSection('s', 'Section', $fields, $conditionField, $conditionValue);
    }
}
