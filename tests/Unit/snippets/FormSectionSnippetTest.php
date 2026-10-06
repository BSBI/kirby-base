<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\snippets;

use BSBI\WebBase\forms\ResolvedFormSection;
use BSBI\WebBase\Testing\KirbyTestEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the `form/section` snippet.
 *
 * A section with a title is a `<fieldset>` named by its `<legend>`. Editors can
 * leave a panel-built section's title blank, and a fieldset without a legend is
 * an unnamed group to a screen reader (WCAG 1.3.1, 4.1.2), so an untitled
 * section renders as a plain container instead. The conditional-reveal script
 * finds sections by their data attributes, whichever element they are.
 */
final class FormSectionSnippetTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        KirbyTestEnvironment::boot('kirby-base-form-section-snippet-' . uniqid());
    }

    public function testATitledSectionIsAFieldsetNamedByItsLegend(): void
    {
        $html = $this->render(new ResolvedFormSection('s1', 'Your details', [], null, null));

        $this->assertMatchesRegularExpression('#<fieldset[^>]*id="section-s1"[^>]*>\s*<legend[^>]*>Your details</legend>#', $html);
    }

    public function testAnUntitledSectionIsNotAnUnnamedFieldset(): void
    {
        $html = $this->render(new ResolvedFormSection('s2', '', [], null, null));

        $this->assertStringNotContainsString('<fieldset', $html);
        $this->assertStringNotContainsString('<legend', $html);
        $this->assertMatchesRegularExpression('#<div[^>]*class="form-section"[^>]*id="section-s2"#', $html);
    }

    public function testConditionalSectionsKeepTheirDataAttributesEitherWay(): void
    {
        foreach (['Tell us more', ''] as $title) {
            $html = $this->render(new ResolvedFormSection('s3', $title, [], 'enjoyed', 'No'));

            $this->assertStringContainsString('data-condition-field="enjoyed"', $html);
            $this->assertStringContainsString('data-condition-value="No"', $html);
            $this->assertStringContainsString('style="display:none"', $html);
        }
    }

    public function testTheTitleIsEscaped(): void
    {
        $html = $this->render(new ResolvedFormSection('s4', 'Fish & <b>chips</b>', [], null, null));

        $this->assertStringContainsString('Fish &amp; &lt;b&gt;chips&lt;/b&gt;', $html);
    }

    /**
     * Renders the section snippet, as Kirby's snippet() would.
     *
     * @param ResolvedFormSection $section
     * @return string The rendered HTML.
     */
    private function render(ResolvedFormSection $section): string
    {
        $renderer = static function (string $snippetFile, array $snippetData): string {
            extract($snippetData);
            ob_start();
            include $snippetFile;

            return (string) ob_get_clean();
        };

        return $renderer(dirname(__DIR__, 3) . '/snippets/form/section.php', ['section' => $section]);
    }
}
