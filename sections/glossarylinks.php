<?php

declare(strict_types=1);

/**
 * Glossary Links Panel Section
 *
 * Lets editors preview the glossary links that could be added to the current
 * page's content (with surrounding context per match), untick any they don't
 * want, and apply the rest. Applying rewrites the page's block content,
 * wrapping each confirmed term in a link to its glossary item page.
 * The section only renders when the `glossary.page` config option is set.
 */
return [
    'props' => [
        'headline' => function (string $headline = 'Glossary Links') {
            return $headline;
        }
    ],
    'computed' => [
        /**
         * ID of the current page, used by the preview/apply API calls.
         */
        'pageId' => function (): string {
            /** @phpstan-ignore variable.undefined ($this is bound to the section by Kirby) */
            return $this->model()->id();
        },
        /**
         * Whether the glossary feature is enabled for this site: either the
         * glossaryLocation site field or the glossary.page option is set.
         */
        'enabled' => function (): bool {
            $field = kirby()->site()->content()->get('glossaryLocation');
            if ($field instanceof \Kirby\Content\Field && $field->isNotEmpty()) {
                return true;
            }
            $path = option('glossary.page', '');
            return is_string($path) && $path !== '';
        }
    ]
];
