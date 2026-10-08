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
 */
return [
    'props' => [
        'headline' => function (string $headline = 'Analysis') {
            return $headline;
        },
    ],
    'computed' => [
        'formTypes' => function (): array {
            $manager = ContentIndexRegistry::get('form_submissions');
            if ($manager === null) {
                return [];
            }
            $counts = [];
            foreach ($manager->query()->get() as $row) {
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
