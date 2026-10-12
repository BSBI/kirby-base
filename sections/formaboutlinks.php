<?php

declare(strict_types=1);

use BSBI\WebBase\forms\about\AboutKinds;

/**
 * Form About Links Panel Section
 *
 * On a panel-built form's page: pick what the feedback is about (an event,
 * project, …, from the kinds ticked in "Feedback is about") and copy a signed
 * link to the form naming it (AboutLink). The Vue section searches with the
 * `forms/about/choices` API route and gets the link from `forms/about/link`;
 * both check the role. This section supplies the form's id and its kinds as
 * last saved.
 */
return [
    'props' => [
        'headline' => function (string $headline = 'Link about an event or page') {
            return $headline;
        },
    ],
    'computed' => [
        'formId' => function (): string {
            $model = $this->model();
            return $model instanceof \Kirby\Cms\Page ? $model->id() : '';
        },
        'kinds' => function (): array {
            $model = $this->model();
            if (!$model instanceof \Kirby\Cms\Page) {
                return [];
            }
            $kinds = new AboutKinds($this->kirby());
            $all = $kinds->all();
            return array_map(
                static fn(string $kind): string => $all[$kind]['label'],
                $kinds->forForm($model)
            );
        },
    ],
];
