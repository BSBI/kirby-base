<?php

declare(strict_types=1);

namespace BSBI\WebBase\forms\panel;

use Kirby\Cms\Page;

/**
 * Decides which question keys a save may not lose.
 *
 * A question's key (its field name, or `f_` plus its block id) is its
 * identity in stored responses and CSV columns. Once a form's responses use
 * a key, renaming or removing that question would break the column, so the
 * key is locked. Keys no response uses (a question added since the last
 * response) stay free. Labels, help text, options and order never lock.
 */
final readonly class FormKeyLock
{
    /** Most questions, and forms per question, named in a message. */
    public const MAX_LISTED = 5;

    /**
     * @param ResponseKeySource $responses Reads the keys stored responses use
     */
    public function __construct(private ResponseKeySource $responses)
    {
    }

    /**
     * Returns the keys present before and missing after, in their order before.
     *
     * @param string[] $before
     * @param string[] $after
     * @return list<string>
     */
    public static function lostKeys(array $before, array $after): array
    {
        return array_values(array_diff($before, $after));
    }

    /**
     * Returns the locked keys each change would lose, with the question's
     * label (as it was) and the titles of the forms whose responses use it.
     * Responses are only read for forms that lose a key.
     *
     * @param list<array{form: Page, before: array<string, array{label: string, column: string}>, after: array<string, array{label: string, column: string}>}> $changes
     *        Each affected form's questions (as getSubmissionColumns() gives them) before and after the save
     * @return array<string, array{label: string, forms: list<string>}> Keyed by question key
     */
    public function conflicts(array $changes): array
    {
        $conflicts = [];
        foreach ($changes as $change) {
            $lost = self::lostKeys(array_keys($change['before']), array_keys($change['after']));
            if ($lost === []) {
                continue;
            }
            foreach ($this->responses->usedKeys($change['form'], $lost) as $key) {
                $conflicts[$key] ??= ['label' => $change['before'][$key]['label'], 'forms' => []];
                $conflicts[$key]['forms'][] = self::titleOf($change['form']);
            }
        }
        return $conflicts;
    }

    /**
     * Returns the form's questions that its stored responses use, as key =>
     * label, in form order.
     *
     * @param Page                                                $form    A panel-built form page
     * @param array<string, array{label: string, column: string}> $columns Its questions, from getSubmissionColumns()
     * @return array<string, string>
     */
    public function lockedQuestions(Page $form, array $columns): array
    {
        if ($columns === [] || !$this->responses->hasResponses($form)) {
            return [];
        }
        $locked = [];
        foreach ($this->responses->usedKeys($form, array_keys($columns)) as $key) {
            $locked[$key] = $columns[$key]['label'];
        }
        return $locked;
    }

    /**
     * Returns the editor-facing message refusing a save, naming the questions
     * and forms involved (a few of each, then how many more).
     *
     * @param array<string, array{label: string, forms: list<string>}> $conflicts From conflicts()
     */
    public static function message(array $conflicts): string
    {
        $parts = [];
        foreach (array_slice($conflicts, 0, self::MAX_LISTED, true) as $key => $conflict) {
            $forms = self::list(
                array_map(static fn(string $title): string => '"' . $title . '"', $conflict['forms']),
                'other form'
            );
            $parts[] = [self::questionName((string) $key, $conflict['label']), $forms];
        }

        if (count($conflicts) === 1) {
            return sprintf(
                'This can\'t be saved: %s is used by responses to %s, so it can\'t be renamed or removed. '
                . 'Undo that change, then save. You can still change its label, help text and options.',
                $parts[0][0],
                $parts[0][1]
            );
        }

        $listed = array_map(static fn(array $part): string => sprintf('%s, used by responses to %s', ...$part), $parts);
        $more = count($conflicts) - count($parts);
        if ($more > 0) {
            $listed[] = sprintf('and %d more question%s', $more, $more === 1 ? '' : 's');
        }
        return sprintf(
            'This can\'t be saved: these questions are used by stored responses, so they can\'t be renamed '
            . 'or removed: %s. Undo those changes, then save. You can still change their labels, help text '
            . 'and options.',
            implode('; ', $listed)
        );
    }

    /**
     * Returns how a question is named in a message: its label, with its key.
     */
    private static function questionName(string $key, string $label): string
    {
        return $label !== '' ? sprintf('"%s" (field name "%s")', $label, $key) : sprintf('field name "%s"', $key);
    }

    /**
     * Returns up to MAX_LISTED items joined with commas, then "and N more <noun>s".
     *
     * @param string[] $items
     * @param string   $noun Singular noun for the overflow count
     */
    private static function list(array $items, string $noun): string
    {
        $shown = array_slice($items, 0, self::MAX_LISTED);
        $more = count($items) - count($shown);
        $text = implode(', ', $shown);
        return $more > 0 ? sprintf('%s and %d %s%s', $text, $more, $noun, $more === 1 ? '' : 's') : $text;
    }

    /**
     * Returns a form's title, or its slug if it has none.
     */
    private static function titleOf(Page $form): string
    {
        $title = PanelContent::text($form->content(), 'title');
        return $title !== '' ? $title : $form->slug();
    }
}
