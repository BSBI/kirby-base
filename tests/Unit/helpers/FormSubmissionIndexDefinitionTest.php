<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\FormSubmissionIndexDefinition;
use PHPUnit\Framework\TestCase;

/**
 * Tests for FormSubmissionIndexDefinition.
 */
final class FormSubmissionIndexDefinitionTest extends TestCase
{
    private FormSubmissionIndexDefinition $definition;

    protected function setUp(): void
    {
        $this->definition = new FormSubmissionIndexDefinition();
    }

    public function testGetName(): void
    {
        $this->assertSame('form_submissions', $this->definition->getName());
    }

    public function testGetCollectionName(): void
    {
        $this->assertSame('formSubmissions', $this->definition->getCollectionName());
    }

    public function testGetTemplates(): void
    {
        $this->assertSame(['form_submission'], $this->definition->getTemplates());
    }

    public function testGetColumnsContainsFormType(): void
    {
        $columns = $this->definition->getColumns();
        $this->assertArrayHasKey('form_type', $columns);
    }

    public function testGetColumnsContainsSubmittedAt(): void
    {
        $columns = $this->definition->getColumns();
        $this->assertArrayHasKey('submitted_at', $columns);
    }

    public function testGetColumnsContainsAboutWithAnIndex(): void
    {
        $this->assertArrayHasKey('about', $this->definition->getColumns());
        $this->assertNotEmpty(array_filter(
            $this->definition->getIndexes(),
            static fn(string $sql): bool => str_contains($sql, '(about)')
        ));
    }

    public function testGetColumnsDoesNotContainPageId(): void
    {
        // page_id is added automatically by ContentIndexManager as the primary key
        $columns = $this->definition->getColumns();
        $this->assertArrayNotHasKey('page_id', $columns);
    }

    public function testGetIndexesIncludesFormTypeIndex(): void
    {
        $indexes = $this->definition->getIndexes();
        $found = false;
        foreach ($indexes as $index) {
            if (str_contains($index, 'form_type')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Expected an index on form_type');
    }

    public function testGetIndexesIncludesSubmittedAtIndex(): void
    {
        $indexes = $this->definition->getIndexes();
        $found = false;
        foreach ($indexes as $index) {
            if (str_contains($index, 'submitted_at')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Expected an index on submitted_at');
    }
}
