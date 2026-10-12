<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\forms\about;

use BSBI\WebBase\forms\about\AboutSubject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AboutSubject: the fields a response stores, and the answers it
 * gives "fill from the link" questions.
 */
final class AboutSubjectTest extends TestCase
{
    public function testFillFromQuestionsAreAnsweredFromTheSubjectWhateverWasPosted(): void
    {
        $walk = new AboutSubject('page://walk', 'Kenfig walk', '2026-09-14', 'walk.sig');

        $this->assertSame(
            ['event_name' => 'Kenfig walk', 'name' => 'Ann', 'event_date' => '2026-09-14'],
            $walk->fillAnswers(
                ['event_name' => 'Something else', 'name' => 'Ann'],
                ['event_name' => 'title', 'event_date' => 'date']
            )
        );
    }

    public function testTheStoredFields(): void
    {
        $this->assertSame(
            ['about' => 'page://walk', 'about_title' => 'Kenfig walk', 'about_date' => ''],
            (new AboutSubject('page://walk', 'Kenfig walk', '', 'walk.sig'))->fields()
        );
    }
}
