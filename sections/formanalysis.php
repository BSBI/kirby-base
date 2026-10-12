<?php

declare(strict_types=1);

use BSBI\WebBase\helpers\ContentIndexRegistry;

/**
 * Form Analysis Panel Section
 *
 * Charts and counts for one form type's responses (FormAnalysis), with
 * filters for one form and a date range. The Vue section loads the analysis
 * from the `forms/analysis` API route, which checks the role; this section
 * only supplies the form types to choose from.
 *
 * On a form's own page (`forThisPage: true`) the analysis is fixed to that
 * form: only the types of its own responses are offered, and there's no form
 * picker.
 *
 * On a page feedback can be about, such as an event (`forSubject: true`), the
 * analysis is fixed to the responses about that page, from any form: only
 * their types are offered, with no form or About picker.
 */
return [
    'props' => [
        'headline' => function (string $headline = 'Analysis') {
            return $headline;
        },
        'forThisPage' => function (bool $forThisPage = false) {
            return $forThisPage;
        },
        'forSubject' => function (bool $forSubject = false) {
            return $forSubject;
        },
    ],
    'computed' => [
        'about' => function (): string {
            $model = $this->model();
            return $this->forSubject && $model instanceof \Kirby\Cms\Page ? $model->uuid()->toString() : '';
        },
        'pageId' => function (): string {
            $model = $this->model();
            return $this->forThisPage && $model instanceof \Kirby\Cms\Page ? $model->id() : '';
        },
        'formTypes' => function (): array {
            $manager = ContentIndexRegistry::get('form_submissions');
            if ($manager === null) {
                return [];
            }
            $query = $manager->query();
            $pageId = $this->pageId;
            if ($this->forSubject) {
                // Responses about this page, from any form.
                $query = $query->where('about', $this->about);
            } elseif ($pageId !== '') {
                // This form's own responses: its direct children.
                $query = $query->whereAnyStartsWith(['page_id'], $pageId . '/');
            }
            $counts = [];
            foreach ($query->get() as $row) {
                $rowPage = is_string($row['page_id'] ?? null) ? $row['page_id'] : '';
                if ($pageId !== '' && dirname($rowPage) !== $pageId) {
                    continue;
                }
                $type = is_string($row['form_type'] ?? null) && $row['form_type'] !== '' ? $row['form_type'] : '(untyped)';
                $counts[$type] = ($counts[$type] ?? 0) + 1;
            }
            arsort($counts);
            $types = [];
            foreach ($counts as $type => $count) {
                $types[] = ['type' => (string) $type, 'count' => $count];
            }
            return $types;
        },
    ],
];
