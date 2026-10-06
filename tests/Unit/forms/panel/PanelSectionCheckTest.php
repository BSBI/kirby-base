<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\forms\panel;

use BSBI\WebBase\forms\panel\PanelSectionCheck;
use BSBI\WebBase\Testing\KirbyTestEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PanelSectionCheck: what a library section gives a form (its
 * questions in order, inherited ones marked) and the section's own problems.
 */
final class PanelSectionCheckTest extends TestCase
{
    use PanelFormFixtures;

    public static function setUpBeforeClass(): void
    {
        KirbyTestEnvironment::boot('kirby-base-panel-section-check-' . uniqid());
    }

    public function testASectionListsItsOwnQuestions(): void
    {
        $section = $this->sectionPage([
            $this->blockData('form-textbox', ['label' => 'Your name', 'name' => 'name']),
            $this->blockData('form-radio-group', ['label' => 'Contact me by', 'options' => "Email\nPhone"]),
            $this->blockData('form-info', ['text' => 'Some *help*']),
        ], slug: 'contact');

        $check = (new PanelSectionCheck($this->resolver([])))->check($section);

        $this->assertSame([
            ['label' => 'Your name', 'type' => 'Text input', 'from' => ''],
            ['label' => 'Contact me by', 'type' => 'Radio buttons', 'from' => ''],
            ['label' => 'Some *help*', 'type' => 'Text (display only)', 'from' => ''],
        ], $check['questions']);
        $this->assertSame([], $check['problems']);
    }

    public function testAVariationListsInheritedQuestionsFirstWithTheirSection(): void
    {
        $a = $this->sectionPage([$this->blockData('form-textbox', ['label' => 'A', 'name' => 'a'])], slug: 'base-a');
        $b = $this->sectionPage([$this->blockData('form-select', ['label' => 'B', 'name' => 'b', 'options' => 'X'])], extends: 'page://a', slug: 'variation-b');
        $c = $this->sectionPage([$this->blockData('form-textarea', ['label' => 'C', 'name' => 'c'])], extends: 'page://b', slug: 'variation-c');

        $check = (new PanelSectionCheck($this->resolver(['page://a' => $a, 'page://b' => $b])))->check($c);

        $this->assertSame([
            ['label' => 'A', 'type' => 'Text input', 'from' => 'base-a'],
            ['label' => 'B', 'type' => 'Dropdown', 'from' => 'variation-b'],
            ['label' => 'C', 'type' => 'Text area', 'from' => ''],
        ], $check['questions']);
    }

    public function testChainProblemsAreReported(): void
    {
        $a = $this->sectionPage([$this->blockData('form-textbox', ['label' => 'A', 'name' => 'a'])], extends: 'page://b', slug: 'a');
        $b = $this->sectionPage([$this->blockData('form-textbox', ['label' => 'B', 'name' => 'b'])], extends: 'page://a', slug: 'b');

        $check = (new PanelSectionCheck($this->resolver(['page://a' => $a, 'page://b' => $b])))->check($a);

        $this->assertStringContainsString('loop', $check['problems'][0]);
    }

    public function testDuplicateAndInvalidFieldNamesAcrossTheChainAreReported(): void
    {
        $base = $this->sectionPage([$this->blockData('form-textbox', ['label' => 'Email', 'name' => 'email'])], slug: 'base');
        $variation = $this->sectionPage([
            $this->blockData('form-textbox', ['label' => 'Email again', 'name' => 'email']),
            $this->blockData('form-textbox', ['label' => 'Bad', 'name' => '9bad']),
        ], extends: 'page://base', slug: 'variation');

        $check = (new PanelSectionCheck($this->resolver(['page://base' => $base])))->check($variation);

        $this->assertCount(2, $check['problems']);
        $this->assertStringContainsString('"email" is used more than once', $check['problems'][0]);
        $this->assertStringContainsString('"9bad" is not allowed', $check['problems'][1]);
    }
}
