<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\forms;

use BSBI\WebBase\forms\FormSubmissionBuilder;
use Kirby\Data\Data;
use PHPUnit\Framework\TestCase;

/**
 * Tests for FormSubmissionBuilder: turning POST data into the items stored on
 * a form_submission page.
 */
final class FormSubmissionBuilderTest extends TestCase
{
    public function testWithoutAMapEveryPostKeyIsStoredAsBefore(): void
    {
        $items = (new FormSubmissionBuilder())->items([
            'csrf'          => 'token',
            'full_name'     => 'Ann Example',
            'contact-pref'  => 'Email',
            'topics'        => ['Grasses', 'Sedges'],
            ''              => 'ignored',
            'submit'        => 'Send',
        ]);

        $this->assertSame([
            ['question' => 'Full Name', 'answer' => 'Ann Example'],
            ['question' => 'Contact Pref', 'answer' => 'Email'],
            ['question' => 'Topics', 'answer' => 'Grasses, Sedges'],
        ], $items);
    }

    public function testLegacyYamlIsUnchanged(): void
    {
        $items = (new FormSubmissionBuilder())->items(['email' => 'a@example.org', 'notes' => "Line 1\nLine 2"]);

        $this->assertSame(
            "- \n  question: Email\n  answer: a@example.org\n- \n  question: Notes\n  answer: |\n    Line 1\n    Line 2\n",
            Data::encode($items, 'yaml')
        );
    }

    public function testWithAMapOnlyDefinedKeysAreStoredWithLabelAndColumn(): void
    {
        $items = (new FormSubmissionBuilder())->items(
            ['email' => 'a@example.org', 'f_1a2b3c4d' => 'Yes', 'injected' => 'nope', 'csrf' => 'x'],
            [
                'email'      => ['label' => 'Your email', 'column' => 'email'],
                'f_1a2b3c4d' => ['label' => 'Did you enjoy it?', 'column' => 'enjoyed'],
            ]
        );

        $this->assertSame([
            ['question' => 'Your email', 'answer' => 'a@example.org', 'key' => 'email', 'column' => 'email'],
            ['question' => 'Did you enjoy it?', 'answer' => 'Yes', 'key' => 'f_1a2b3c4d', 'column' => 'enjoyed'],
        ], $items);
    }

    public function testWithAMapItemsFollowTheMapOrderAndUnansweredKeysAreBlank(): void
    {
        $items = (new FormSubmissionBuilder())->items(
            ['second' => 'B'],
            [
                'first'  => ['label' => 'First', 'column' => 'first'],
                'second' => ['label' => 'Second', 'column' => 'second'],
            ]
        );

        $this->assertSame(['first', 'second'], array_column($items, 'key'));
        $this->assertSame(['', 'B'], array_column($items, 'answer'));
    }

    public function testRatingMatrixAnswersKeepTheirRowsInOneCell(): void
    {
        $items = (new FormSubmissionBuilder())->items(
            ['rating' => ['venue' => 'Good', 'catering' => 'Poor']],
            ['rating' => ['label' => 'Rate', 'column' => 'rating']]
        );

        $this->assertSame('venue: Good, catering: Poor', $items[0]['answer']);
    }

    public function testCheckboxListsAreJoinedAndNestedJunkIsDropped(): void
    {
        $items = (new FormSubmissionBuilder())->items(
            ['topics' => ['Grasses', ['nested'], 'Sedges'], 'count' => 3],
            [
                'topics' => ['label' => 'Topics', 'column' => 'topics'],
                'count'  => ['label' => 'Count', 'column' => 'count'],
            ]
        );

        $this->assertSame(['Grasses, Sedges', '3'], array_column($items, 'answer'));
    }
}
