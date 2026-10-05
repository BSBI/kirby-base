<?php

declare(strict_types=1);

use BSBI\WebBase\helpers\ContentIndexRegistry;

/**
 * Form Submissions Index Panel Section
 *
 * Displays a summary of all form_submission pages across the entire site,
 * grouped by form_type, with per-type submission counts and CSV export links.
 *
 * Uses the SQLite form_submissions content index for fast lookups without
 * loading all Kirby pages into memory.
 *
 * Intended for the site-level dashboard, not individual form pages.
 */
return [
    'props' => [
        'headline' => function (string $headline = 'Form Submissions') {
            return $headline;
        }
    ],
    'computed' => [
        /**
         * All form_submission pages grouped by form_type, with counts and
         * per-type export URLs (wide, and long: one row per answer).
         *
         * @return array<int, array{formType: string, count: int, exportUrl: string, longExportUrl: string}>
         */
        'formTypes' => function (): array {
            $manager = ContentIndexRegistry::get('form_submissions');

            if ($manager === null) {
                return [];
            }

            $rows = $manager->query()->get();

            $counts = [];
            foreach ($rows as $row) {
                $formType = (string) ($row['form_type'] ?? '');
                if ($formType === '') {
                    $formType = '(untyped)';
                }
                $counts[$formType] = ($counts[$formType] ?? 0) + 1;
            }

            $baseUrl = kirby()->url() . '/form-export-all';
            $result  = [];
            foreach ($counts as $formType => $count) {
                $exportUrl = $baseUrl . '?form_type=' . urlencode($formType);
                $result[] = [
                    'formType'      => $formType,
                    'count'         => $count,
                    'exportUrl'     => $exportUrl,
                    'longExportUrl' => $exportUrl . '&format=long',
                ];
            }

            usort($result, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);

            return $result;
        },

        /**
         * Total submission count across all form types.
         */
        'totalCount' => function (): int {
            $manager = ContentIndexRegistry::get('form_submissions');

            if ($manager === null) {
                return 0;
            }

            return $manager->query()->count();
        },

        /**
         * URL to export all submissions regardless of form type.
         */
        'exportAllUrl' => function (): string {
            return kirby()->url() . '/form-export-all';
        },

        /**
         * URL to export all submissions as a long CSV (one row per answer).
         */
        'exportAllLongUrl' => function (): string {
            return kirby()->url() . '/form-export-all?format=long';
        },
    ],
];
