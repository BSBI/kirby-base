<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\forms;

use BSBI\WebBase\forms\FormSubmissionSlug;
use PHPUnit\Framework\TestCase;

/**
 * Tests for FormSubmissionSlug: naming a new form_submission page.
 */
final class FormSubmissionSlugTest extends TestCase
{
    public function testAFreeTimestampIsUsedAsIs(): void
    {
        $name = FormSubmissionSlug::next('Oct-5-16.24.38', static fn(string $slug): bool => false);

        $this->assertSame(['slug' => 'oct-5-16-24-38', 'title' => 'Submission: Oct-5-16.24.38'], $name);
    }

    public function testTheCheckUsesTheSlugKirbyWillStore(): void
    {
        // Kirby stores "Oct-5-16.24.38" as "oct-5-16-24-38"; checking the raw
        // timestamp never found the earlier submission, so a second one in the
        // same second failed to save.
        $taken = ['oct-5-16-24-38', 'oct-5-16-24-38-1'];

        $name = FormSubmissionSlug::next(
            'Oct-5-16.24.38',
            static fn(string $slug): bool => in_array($slug, $taken, true)
        );

        $this->assertSame(['slug' => 'oct-5-16-24-38-2', 'title' => 'Submission: Oct-5-16.24.38-2'], $name);
    }
}
