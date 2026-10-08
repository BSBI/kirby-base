<?php

declare(strict_types=1);

/**
 * Forms in use Panel Section
 *
 * Every form on the site (FormsInUse): where it is, its kind, form type,
 * status, responses, latest response and content folder. The Vue section
 * loads the list from the `forms/in-use` API route, which checks the role.
 */
return [
    'props' => [
        'headline' => function (string $headline = 'Forms in use') {
            return $headline;
        },
    ],
];
