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
 * both check the role. This section supplies the form's id, every kind, and
 * the kinds ticked as last saved; it follows unsaved ticks too, and says when
 * the form must be saved before a link works.
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
        // Every configured kind, kind => label; the Vue section follows the
        // form's "Feedback is about" ticks as they change, before a save.
        'allKinds' => function (): array {
            return array_map(static fn(array $kind): string => $kind['label'], (new AboutKinds($this->kirby()))->all());
        },
        // The kinds ticked as last saved: a link works only for these.
        'savedKinds' => function (): array {
            $model = $this->model();
            return $model instanceof \Kirby\Cms\Page ? (new AboutKinds($this->kirby()))->forForm($model) : [];
        },
    ],
];
