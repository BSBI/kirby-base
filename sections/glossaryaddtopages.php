<?php

declare(strict_types=1);

/**
 * Glossary "Add to Pages" Panel Section
 *
 * Shown on glossary item pages. Lets an editor scan the whole site for the
 * item's term and add glossary links wherever it appears unlinked, batch by
 * batch, with progress. Each run appends per-page results to a change log
 * stored on the item (addtopageslog), displayed here so the editor can
 * review what changed — and clear the log once done.
 */
return [
    'props' => [
        'headline' => function (string $headline = 'Add to Pages') {
            return $headline;
        }
    ],
    'computed' => [
        /**
         * ID of the glossary item page.
         */
        'pageId' => function (): string {
            /** @phpstan-ignore variable.undefined ($this is bound to the section by Kirby) */
            return $this->model()->id();
        },
        /**
         * The glossary term (the item page's title).
         */
        'term' => function (): string {
            /** @phpstan-ignore variable.undefined ($this is bound to the section by Kirby) */
            return (string)$this->model()->title()->value();
        },
        /**
         * Whether the glossary feature is enabled for this site.
         */
        'enabled' => function (): bool {
            $field = kirby()->site()->content()->get('glossaryLocation');
            if ($field instanceof \Kirby\Content\Field && $field->isNotEmpty()) {
                return true;
            }
            $path = option('glossary.page', '');
            return is_string($path) && $path !== '';
        },
        /**
         * The stored change log entries from previous runs.
         */
        'log' => function (): array {
            /** @phpstan-ignore variable.undefined ($this is bound to the section by Kirby) */
            $field = $this->model()->content()->get('addtopageslog');
            $raw = $field instanceof \Kirby\Content\Field ? $field->value() : null;
            $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
            return is_array($decoded) ? $decoded : [];
        }
    ]
];
