<?php

declare(strict_types=1);

use BSBI\WebBase\forms\panel\FormKeyLockGuard;
use BSBI\WebBase\forms\panel\KirbySectionPageResolver;
use BSBI\WebBase\forms\panel\PanelSectionCheck;

/**
 * Form Section Check Panel Section
 *
 * On a library section page: the questions the section gives a form, in order
 * (inherited ones marked with the section they come from), any problems
 * with the section itself, and the questions that stored responses to forms
 * using it lock (FormKeyLockGuard). Editor text is rendered through Vue interpolation,
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

        /**
         * Questions whose field names responses to forms using this section
         * use, each with those forms' titles.
         *
         * @return list<array{label: string, key: string, forms: list<string>}>
         */
        'locked' => function (): array {
            $model = $this->model();
            if (!$model instanceof \Kirby\Cms\Page) {
                return [];
            }
            try {
                return FormKeyLockGuard::forKirby(kirby())->lockedInSection($model);
            } catch (Throwable $e) {
                \BSBI\WebBase\helpers\KirbyBaseHelper::writeToLogFile('search-index', 'Section check: locked questions unavailable: ' . $e->getMessage());
                return [];
            }
        },
    ],
];
