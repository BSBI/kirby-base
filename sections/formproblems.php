<?php

declare(strict_types=1);

use BSBI\WebBase\forms\panel\KirbySectionPageResolver;
use BSBI\WebBase\forms\panel\PanelFormDefinition;

/**
 * Form Problems Panel Section
 *
 * Lists what had to be left out of a panel-built form (missing library
 * sections, duplicate field names, unusable conditions), so the editor can fix
 * it. Shown on the form page; the live form keeps working meanwhile.
 *
 * A section rather than an info field: problem messages quote editor text, and
 * an info field would run it through KirbyText and Markdown.
 */
return [
    'props' => [
        'headline' => function (string $headline = 'Form check') {
            return $headline;
        },
        /** Name of the blocks field holding the form's sections. */
        'field' => function (string $field = 'formSections') {
            return $field;
        },
    ],
    'computed' => [
        /**
         * Editor-facing problem descriptions; empty when the form is sound.
         *
         * @return string[]
         */
        'problems' => function (): array {
            $model = $this->model();
            if (!$model instanceof \Kirby\Cms\Page) {
                return [];
            }
            $definition = new PanelFormDefinition($model, '', new KirbySectionPageResolver(kirby()), $this->field);
            return $definition->validate();
        },
    ],
];
