<?php

declare(strict_types=1);

use BSBI\WebBase\forms\FormBuilderOptions;
use Kirby\Cms\Page;

/**
 * A box in a library section block's Adjust row (one line: a label, help text or scale label):
 * a text field that shows the chosen question's library wording greyed out
 * while it is blank, with a button that copies it in to be edited. A blank box
 * keeps the library's wording, so later library edits still come through.
 *
 * `library` holds the wording of every library question on the form, as last
 * saved (FormBuilderOptions::libraryWordingFor()). The Panel component
 * (index.js, `formadjusttext`) picks the part named like this field for the
 * question the row's `question` select has chosen, and hides the box when
 * that question has no such part (rows on a text question) and it is empty.
 */
return [
    'extends' => 'text',
    'computed' => [
        /**
         * Library wording by question key, then by part.
         *
         * @return array<string, array<string, string>>
         */
        'library' => function (): array {
            $model = $this->model();
            return $model instanceof Page ? FormBuilderOptions::libraryWordingFor($model) : [];
        },
    ],
];
