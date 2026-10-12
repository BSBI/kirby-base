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

    public function testADetailFillsItsQuestionAndABlankValueLeavesItToBeAsked(): void
    {
        $walk = new AboutSubject('page://walk', 'Kenfig walk', '', 'walk.sig', 'Event', '', ['ledBy' => ['label' => 'Led by', 'value' => 'Ann Leader']]);
        $fillFrom = ['event_name' => 'title', 'event_date' => 'date', 'leader' => 'ledBy', 'other' => 'unknown'];

        $this->assertSame(['event_name' => 'title', 'leader' => 'ledBy'], $walk->answered($fillFrom));
        $this->assertSame(
            ['event_date' => '2026-01-01', 'event_name' => 'Kenfig walk', 'leader' => 'Ann Leader'],
            $walk->fillAnswers(['event_date' => '2026-01-01'], $fillFrom),
            'an undated page leaves the posted date alone'
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
