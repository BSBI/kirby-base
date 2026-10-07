<?php

declare(strict_types=1);

namespace BSBI\WebBase\forms\panel;

use Kirby\Cms\Page;

/**
 * Reads question keys from a form's stored responses: its `form_submission`
 * child pages, whose `submission` field holds one `{question, answer, key,
 * column}` item per question.
 */
final readonly class KirbyResponseKeySource implements ResponseKeySource
{
    /** Template of a stored response. */
    public const TEMPLATE = 'form_submission';

    /**
     * Returns true if the form has any stored responses.
     *
     * @param Page $form A panel-built form page
     */
    public function hasResponses(Page $form): bool
    {
        return $this->responses($form)->count() > 0;
    }

    /**
     * Returns those of the given keys that at least one stored response uses,
     * in the order given. Stops reading responses once every key is found.
     *
     * @param Page     $form A panel-built form page
     * @param string[] $keys Question keys to look for
     * @return list<string>
     */
    public function usedKeys(Page $form, array $keys): array
    {
        $wanted = array_fill_keys($keys, true);
        $found = [];
        if ($wanted === []) {
            return [];
        }

        foreach ($this->responses($form) as $response) {
            foreach (PanelContent::field($response->content(), 'submission')->yaml() as $item) {
                $key = is_array($item) ? ($item['key'] ?? null) : null;
                if (is_string($key) && isset($wanted[$key])) {
                    $found[$key] = true;
                    unset($wanted[$key]);
                }
            }
            if ($wanted === []) {
                break;
            }
        }

        return array_values(array_filter($keys, static fn(string $key): bool => isset($found[$key])));
    }

    /**
     * Returns the form's stored responses, published or not.
     *
     * @param Page $form
     * @return \Kirby\Cms\Pages<string, Page>
     */
    private function responses(Page $form): \Kirby\Cms\Pages
    {
        return $form->childrenAndDrafts()->filterBy('intendedTemplate', self::TEMPLATE);
    }
}
