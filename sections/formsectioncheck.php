<?php

declare(strict_types=1);

use BSBI\WebBase\forms\panel\KirbySectionPageResolver;
use BSBI\WebBase\forms\panel\PanelSectionCheck;

/**
 * Form Section Check Panel Section
 *
 * On a library section page: the questions the section gives a form, in order
 * (inherited ones marked with the section they come from), and any problems
 * with the section itself. Editor text is rendered through Vue interpolation,
 * so it is escaped.
 */
return [
    'props' => [
        'headline' => function (string $headline = 'What forms get') {
            return $headline;
        },
    ],
    'computed' => [
        /**
         * The section's questions and problems.
         *
         * @return array{questions: list<array{label: string, type: string, from: string}>, problems: list<string>}
         */
        'check' => function (): array {
            $model = $this->model();
            if (!$model instanceof \Kirby\Cms\Page) {
                return ['questions' => [], 'problems' => []];
            }
            return (new PanelSectionCheck(new KirbySectionPageResolver(kirby())))->check($model);
        },
    ],
];
