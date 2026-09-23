<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\snippets;

use BSBI\WebBase\forms\ResolvedFormField;
use BSBI\WebBase\Testing\KirbyTestEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the `form/field-radio-group` and `form/field-checkbox-group`
 * snippets (bsbi-web#697).
 *
 * The group's question used to render as a bold paragraph, so a screen reader
 * user tabbing between the options heard "Yes", "No", "Abstain" with no hint of
 * which question they answered. Each group is now a `<fieldset>` whose
 * `<legend>` is the question, with any help text tied to it by
 * `aria-describedby` (WCAG 1.3.1).
 */
final class FormGroupFieldSnippetTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $fixture = sys_get_temp_dir() . '/kirby-base-form-group-fixture-' . uniqid();
        mkdir($fixture, 0777, true);
        file_put_contents($fixture . '/site.txt', "Title: Test Site\n");

        KirbyTestEnvironment::bootWithContent($fixture, 'kirby-base-form-group-snippet', [
            'snippets' => [
                'form/checkbox' => dirname(__DIR__, 3) . '/snippets/form/checkbox.php',
            ],
        ]);
    }

    /**
     * The two group snippets, with the field type each renders.
     *
     * @return array<string, array{string}>
     */
    public static function groupSnippets(): array
    {
        return [
            'radio group'    => ['radio-group'],
            'checkbox group' => ['checkbox-group'],
        ];
    }

    /**
     * Renders a group snippet for the given field, as Kirby's snippet() would.
     *
     * @param string $type The field type, e.g. `radio-group`.
     * @param ResolvedFormField $field The field to render.
     * @return string The rendered HTML.
     */
    private function render(string $type, ResolvedFormField $field): string
    {
        $renderer = static function (string $snippetFile, array $snippetData): string {
            extract($snippetData);
            ob_start();
            include $snippetFile;

            return (string)ob_get_clean();
        };

        return $renderer(dirname(__DIR__, 3) . '/snippets/form/field-' . $type . '.php', ['field' => $field]);
    }

    #[DataProvider('groupSnippets')]
    public function testQuestionIsTheLegendOfAFieldsetWrappingTheOptions(string $type): void
    {
        $html = $this->render($type, new ResolvedFormField(
            type: $type,
            name: 'resolution_1',
            label: 'Resolution 1: adopt the accounts',
            options: ['Yes', 'No', 'Abstain'],
        ));

        $this->assertMatchesRegularExpression(
            '#<fieldset[^>]*>\s*<legend[^>]*>\s*Resolution 1: adopt the accounts#',
            $html
        );
        $this->assertStringNotContainsString('<p><strong>', $html);

        // Every option sits inside the fieldset.
        $start = (int)strpos($html, '<fieldset');
        $end = (int)strrpos($html, '</fieldset>');
        foreach (['Yes', 'No', 'Abstain'] as $option) {
            $position = strpos($html, 'value="' . $option . '"');
            $this->assertIsInt($position);
            $this->assertGreaterThan($start, $position);
            $this->assertLessThan($end, $position);
        }
    }

    #[DataProvider('groupSnippets')]
    public function testRequiredMarkerIsInsideTheLegend(string $type): void
    {
        $html = $this->render($type, new ResolvedFormField(
            type: $type,
            name: 'q',
            label: 'Question',
            required: true,
            options: ['A', 'B'],
        ));

        $this->assertMatchesRegularExpression('#<legend[^>]*>.*\(required\).*</legend>#s', $html);
    }

    #[DataProvider('groupSnippets')]
    public function testHelpTextDescribesTheFieldset(string $type): void
    {
        $html = $this->render($type, new ResolvedFormField(
            type: $type,
            name: 'resolution_2',
            label: 'Resolution 2',
            help: 'That the report of the trustees be received.',
            options: ['Yes', 'No'],
        ));

        $this->assertStringContainsString('<fieldset aria-describedby="resolution_2-help"', $html);
        $this->assertMatchesRegularExpression(
            '#id="resolution_2-help"[^>]*>That the report of the trustees be received.#',
            $html
        );
    }

    #[DataProvider('groupSnippets')]
    public function testNoDescribedByWithoutHelpText(string $type): void
    {
        $html = $this->render($type, new ResolvedFormField(
            type: $type,
            name: 'q',
            label: 'Question',
            options: ['A'],
        ));

        $this->assertStringNotContainsString('aria-describedby', $html);
    }
}
