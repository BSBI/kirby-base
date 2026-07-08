<?php

use BSBI\WebBase\helpers\ContentIndexRegistry;
use BSBI\WebBase\helpers\KirbyInternalHelper;
use BSBI\WebBase\helpers\SearchIndexHelper;
use Kirby\Http\Response;
use Kirby\Toolkit\Tpl;

return [
    [
        'pattern' => 'form-export/(:all)',
        'method'  => 'GET',
        'action'  => function (string $pageId): Response {
            $helper = new KirbyInternalHelper();

            if (!$helper->isCurrentUserAdminOrEditor()) {
                return new Response(
                    'You must be an administrator or editor to export submissions.',
                    'text/plain',
                    403
                );
            }

            $page = $helper->findKirbyPageOrDraft($pageId);
            if ($page === null) {
                return new Response('Page not found.', 'text/plain', 404);
            }

            $submissions = $page->children()->template('form_submission');

            // Pass 1: collect all unique questions across every submission,
            // preserving the first-seen order so later submissions with
            // extra questions simply append new columns at the right.
            $allQuestions = [];
            $submissionRows = [];

            foreach ($submissions as $submission) {
                $rowData = ['_title' => $submission->title()->value()];

                foreach ($submission->submission()->toStructure() as $item) {
                    $question = $item->question()->value();
                    $answer   = $item->answer()->value();

                    if (!in_array($question, $allQuestions, true)) {
                        $allQuestions[] = $question;
                    }

                    $rowData[$question] = $answer;
                }

                $submissionRows[] = $rowData;
            }

            // Pass 2: build the CSV in memory.
            ob_start();
            $handle = fopen('php://output', 'w');

            // Row 1: form page title (identification row).
            fputcsv($handle, [$page->title()->value()]);

            // Row 2: column headers.
            fputcsv($handle, array_merge(['Submission'], $allQuestions));

            // Data rows: one per submission, answers mapped to the correct column.
            foreach ($submissionRows as $row) {
                $csvRow = [$row['_title']];
                foreach ($allQuestions as $question) {
                    $csvRow[] = $row[$question] ?? '';
                }
                fputcsv($handle, $csvRow);
            }

            fclose($handle);
            $csv = ob_get_clean();

            $filename = 'submissions-' . $page->slug() . '-' . date('Y-m-d') . '.csv';

            return new Response($csv, 'text/csv', 200, [
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Cache-Control'       => 'no-cache, no-store, must-revalidate',
            ]);
        }
    ],
    [
        'pattern' => 'form-export-all',
        'method'  => 'GET',
        'action'  => function (): Response {
            $helper = new KirbyInternalHelper();

            if (!$helper->isCurrentUserAdminOrEditor()) {
                return new Response(
                    'You must be an administrator or editor to export submissions.',
                    'text/plain',
                    403
                );
            }

            $formTypeFilter = (string) kirby()->request()->get('form_type', '');

            // Use the SQLite index to get matching page IDs, then load only those pages.
            $manager = \BSBI\WebBase\helpers\ContentIndexRegistry::get('form_submissions');

            if ($manager === null) {
                return new Response('Form submissions index not available.', 'text/plain', 503);
            }

            $query = $manager->query();

            if ($formTypeFilter !== '') {
                if ($formTypeFilter === '(untyped)') {
                    $query->where('form_type', '');
                } else {
                    $query->where('form_type', $formTypeFilter);
                }
            }

            $pageIds = $query->getPageIds();

            // Pass 1: collect all unique questions across every submission.
            $allQuestions  = [];
            $submissionRows = [];

            foreach ($pageIds as $pageId) {
                $submission = $helper->findKirbyPageOrDraft($pageId);
                if ($submission === null) {
                    continue;
                }

                $formType = (string) $submission->form_type()->value();
                $rowData  = [
                    '_form_type' => $formType !== '' ? $formType : '(untyped)',
                    '_title'     => $submission->title()->value(),
                ];

                foreach ($submission->submission()->toStructure() as $item) {
                    $question = $item->question()->value();
                    $answer   = $item->answer()->value();

                    if (!in_array($question, $allQuestions, true)) {
                        $allQuestions[] = $question;
                    }

                    $rowData[$question] = $answer;
                }

                $submissionRows[] = $rowData;
            }

            // Pass 2: build the CSV in memory.
            ob_start();
            $handle = fopen('php://output', 'w');

            fputcsv($handle, array_merge(['Form Type', 'Submission'], $allQuestions));

            foreach ($submissionRows as $row) {
                $csvRow = [$row['_form_type'], $row['_title']];
                foreach ($allQuestions as $question) {
                    $csvRow[] = $row[$question] ?? '';
                }
                fputcsv($handle, $csvRow);
            }

            fclose($handle);
            $csv = ob_get_clean();

            $suffix   = $formTypeFilter !== '' ? '-' . preg_replace('/[^a-z0-9]+/i', '-', $formTypeFilter) : '-all';
            $filename = 'form-submissions' . $suffix . '-' . date('Y-m-d') . '.csv';

            return new Response($csv, 'text/csv', 200, [
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Cache-Control'       => 'no-cache, no-store, must-revalidate',
            ]);
        }
    ],
    [
        'pattern' => 'logout',
        'action' => function () {
            if ($user = kirby()->user()) {
                $user->logout();
            }

            go('login');
        }
    ],
    [
        'pattern' => 'scheduled-publish',
        'method'  => 'GET|POST',
        'action'  => function () {
            $helper = new KirbyInternalHelper();
            $output = $helper->publishScheduledPages();
            return new Kirby\Cms\Response(
                $output,
                'text/plain',
                200
            );
        }
    ],
    [
        'pattern' => 'cookie-consent',
        'method' => 'POST',
        'action' => function () {
            $helper = new KirbyInternalHelper();
            $helper->processCookieConsent();
        }
    ],
    [
        'pattern' => 'sitemap.xml',
        'action' => function() {
            $pages = site()->pages()->index()->filter(function($p) {
                return $p->isListed() || $p->isInvisible();
            });
            $ignore = kirby()->option('sitemapExclude', ['error']);
            $content = snippet('sitemap', compact('pages', 'ignore'), true);

            return new Kirby\Cms\Response($content, 'application/xml');
        }
    ],
    [
        'pattern' => 'robots.txt',
        'action' => function() {
            $content = snippet('robots-txt',[], true);
            return new Kirby\Cms\Response($content, 'text/plain');
        }
    ],
    [
        'pattern' => '500',
        'action' => function () {
            $exceptionAsString = kirby()->session()->pull('exceptionAsString');

            echo Tpl::load(__DIR__ . '/templates/error-500.php', [
                'userRole' => kirby()->user() ? kirby()->user()->role()->name() : '',
                'exception' => $exceptionAsString,
            ]);
            exit();
        },
    ],
    [
        'pattern' => 'files/(:any)',
        'action'  => function ($slug) {
            // Find the archive page first to narrow the search
            $archivePage = page('file-archive'); // Adjust to your actual page URI

            if ($archivePage) {
                // Search files for a match in the 'alt_slug' field
                $file = $archivePage->files()->findBy('permanentUrl', $slug);

                if ($file) {
                    // Redirect to the actual physical file URL
                    return go($file->mediaUrl());
                }
            }

            // If no file matches, show 404
            return site()->errorPage();
        }
    ],
    [
        'pattern' => 'search-rebuild',
        'method' => 'GET',
        'action' => function () {
            $helper = new KirbyInternalHelper();
            if ($helper->isCurrentUserAdminOrEditor()) {
                try {
                    $searchIndex = new SearchIndexHelper();
                    $result = $searchIndex->rebuildIndex();
                    $searchCount = $result['search_index'];
                    $allPagesCount = $result['all_pages'];
                    return new Response(
                        "Search index rebuilt successfully. Indexed $searchCount pages for site search, $allPagesCount pages for panel search.",
                        'text/plain',
                        200
                    );
                } catch (Exception $e) {
                    return new Response(
                        'Failed to rebuild search index: ' . $e->getMessage(),
                        'text/plain',
                        500
                    );
                }
            }
            return new Response('You must be an administrator to access this page.', 'text/plain', 403);
        }
    ],
    [
        'pattern' => 'search-stats',
        'method' => 'GET',
        'action' => function () {
            $helper = new KirbyInternalHelper();
            if ($helper->isCurrentUserAdminOrEditor()) {
                try {
                    $searchIndex = new SearchIndexHelper();
                    $stats = $searchIndex->getStats();
                    return new Response(
                        json_encode($stats, JSON_PRETTY_PRINT),
                        'application/json',
                        200
                    );
                } catch (Exception $e) {
                    return new Response(
                        json_encode(['error' => $e->getMessage()]),
                        'application/json',
                        500
                    );
                }
            }
            return new Response('You must be an administrator to access this page.', 'text/plain', 403);
        }
    ],
    [
        'pattern' => 'content-index-rebuild',
        'method' => 'GET',
        'action' => function () {
            $helper = new KirbyInternalHelper();
            if (!$helper->isCurrentUserAdminOrEditor()) {
                return new Response('You must be an administrator to access this page.', 'text/plain', 403);
            }

            $name = kirby()->request()->get('name', '');

            // Resolve the target managers up front so a bad ?name= returns a clean 404.
            if (!empty($name)) {
                $manager = ContentIndexRegistry::get($name);
                if ($manager === null) {
                    return new Response("Content index '$name' not found.", 'text/plain', 404);
                }
                $managers = [$name => $manager];
            } else {
                $managers = ContentIndexRegistry::all();
            }

            // A rebuild deletes-and-refills the whole index in one transaction, so for
            // a large index (thousands of pages, first-time thumbnail generation) the
            // web request can run well past PHP's default 30s max_execution_time and
            // 256M memory_limit. Without lifting these the request is killed
            // mid-transaction and SQLite rolls the rebuild back, silently leaving the
            // index unchanged. This stays a normal blocking request (no streaming) so
            // the panel's fetch resolves only once the rebuild has committed and the
            // reloaded stats show the new "last rebuilt" time.
            @set_time_limit(0);
            @ini_set('memory_limit', '512M');

            try {
                $output = "Content index rebuild complete:\n";
                foreach ($managers as $indexName => $manager) {
                    $count = $manager->rebuildIndex($helper);
                    $output .= "  $indexName: $count pages indexed\n";
                }
                return new Response($output, 'text/plain', 200);
            } catch (Throwable $e) {
                // A concurrent rebuild holds a write lock on the index; report it
                // cleanly rather than surfacing a raw PDOException. The rebuild that
                // holds the lock will still complete on its own request.
                if (stripos($e->getMessage(), 'database is locked') !== false) {
                    return new Response(
                        'A rebuild is already in progress for this index. '
                        . 'Please wait for it to finish before starting another.',
                        'text/plain',
                        409
                    );
                }
                return new Response(
                    'Failed to rebuild content index: ' . $e->getMessage(),
                    'text/plain',
                    500
                );
            }
        }
    ],
    [
        'pattern' => 'content-index-stats',
        'method' => 'GET',
        'action' => function () {
            $helper = new KirbyInternalHelper();
            if ($helper->isCurrentUserAdminOrEditor()) {
                try {
                    $stats = [];
                    foreach (ContentIndexRegistry::all() as $manager) {
                        $stats[] = $manager->getStats();
                    }
                    return new Response(
                        json_encode($stats, JSON_PRETTY_PRINT),
                        'application/json',
                        200
                    );
                } catch (Exception $e) {
                    return new Response(
                        json_encode(['error' => $e->getMessage()]),
                        'application/json',
                        500
                    );
                }
            }
            return new Response('You must be an administrator to access this page.', 'text/plain', 403);
        }
    ],
    [
        'pattern' => 'imagebank-index-rebuild',
        'method'  => 'GET',
        'action'  => function () {
            $helper = new KirbyInternalHelper();
            if (!$helper->isCurrentUserAdminOrEditor()) {
                return new Response('You must be an administrator to access this page.', 'text/plain', 403);
            }
            try {
                $imageBankIndex = new \BSBI\WebBase\helpers\ImageBankIndexHelper();
                $count = $imageBankIndex->rebuildIndex();
                return new Response(
                    "imageBank index rebuilt successfully. $count files indexed.",
                    'text/plain',
                    200
                );
            } catch (Exception $e) {
                return new Response(
                    'Failed to rebuild imageBank index: ' . $e->getMessage(),
                    'text/plain',
                    500
                );
            }
        }
    ],
    [
        'pattern' => 'imagebank-index-stats',
        'method'  => 'GET',
        'action'  => function () {
            $helper = new KirbyInternalHelper();
            if (!$helper->isCurrentUserAdminOrEditor()) {
                return new Response('You must be an administrator to access this page.', 'text/plain', 403);
            }
            try {
                if (!\BSBI\WebBase\helpers\ImageBankIndexHelper::isIndexReady()) {
                    return new Response(
                        json_encode(null),
                        'application/json',
                        200
                    );
                }
                $imageBankIndex = new \BSBI\WebBase\helpers\ImageBankIndexHelper();
                return new Response(
                    json_encode($imageBankIndex->getStats(), JSON_PRETTY_PRINT),
                    'application/json',
                    200
                );
            } catch (Exception $e) {
                return new Response(
                    json_encode(['error' => $e->getMessage()]),
                    'application/json',
                    500
                );
            }
        }
    ],
    [
        'pattern' => 'filelinks-index-rebuild',
        'method'  => 'GET',
        'action'  => function () {
            $helper = new KirbyInternalHelper();
            if (!$helper->isCurrentUserAdminOrEditor()) {
                return new Response('You must be an administrator to access this page.', 'text/plain', 403);
            }
            try {
                $fileLinkIndex = new \BSBI\WebBase\helpers\FileLinkIndexHelper();
                $count = $fileLinkIndex->rebuildIndex();
                return new Response(
                    "File-link index rebuilt successfully. $count pages with links indexed.",
                    'text/plain',
                    200
                );
            } catch (Throwable $e) {
                return new Response(
                    'Failed to rebuild file-link index: ' . $e->getMessage(),
                    'text/plain',
                    500
                );
            }
        }
    ],
    [
        'pattern' => 'filelinks-index-stats',
        'method'  => 'GET',
        'action'  => function () {
            $helper = new KirbyInternalHelper();
            if (!$helper->isCurrentUserAdminOrEditor()) {
                return new Response('You must be an administrator to access this page.', 'text/plain', 403);
            }
            try {
                if (!\BSBI\WebBase\helpers\FileLinkIndexHelper::isIndexReady()) {
                    return new Response(json_encode(null), 'application/json', 200);
                }
                $fileLinkIndex = new \BSBI\WebBase\helpers\FileLinkIndexHelper();
                return new Response(
                    json_encode($fileLinkIndex->getStats(), JSON_PRETTY_PRINT),
                    'application/json',
                    200
                );
            } catch (Throwable $e) {
                return new Response(
                    json_encode(['error' => $e->getMessage()]),
                    'application/json',
                    500
                );
            }
        }
    ],
    [
        'pattern' => 'content-index-records',
        'method'  => 'GET',
        'action'  => function () {
            $helper = new KirbyInternalHelper();
            if (!$helper->isCurrentUserAdminOrEditor()) {
                return new Response('You must be an administrator to access this page.', 'text/plain', 403);
            }
            try {
                $name     = (string)kirby()->request()->get('name', '');
                $page     = max(1, (int)kirby()->request()->get('page', 1));
                $pageSize = 20;

                $manager = ContentIndexRegistry::get($name);
                if ($manager === null) {
                    return new Response(json_encode(['error' => "Index '$name' not found."]), 'application/json', 404);
                }

                $total   = $manager->query()->count();
                $rows    = $manager->query()->limit($pageSize)->offset(($page - 1) * $pageSize)->get();
                $columns = $rows !== [] ? array_keys($rows[0]) : [];

                return new Response(
                    json_encode([
                        'rows'       => $rows,
                        'columns'    => $columns,
                        'total'      => $total,
                        'page'       => $page,
                        'pageSize'   => $pageSize,
                        'totalPages' => $pageSize > 0 ? (int)ceil($total / $pageSize) : 0,
                    ], JSON_PRETTY_PRINT),
                    'application/json',
                    200
                );
            } catch (Exception $e) {
                return new Response(json_encode(['error' => $e->getMessage()]), 'application/json', 500);
            }
        }
    ],
    [
        'pattern' => 'search-index-records',
        'method'  => 'GET',
        'action'  => function () {
            $helper = new KirbyInternalHelper();
            if (!$helper->isCurrentUserAdminOrEditor()) {
                return new Response('You must be an administrator to access this page.', 'text/plain', 403);
            }
            try {
                $page     = max(1, (int)kirby()->request()->get('page', 1));
                $pageSize = 20;

                $searchIndex = new SearchIndexHelper();
                $result      = $searchIndex->getRecords($page, $pageSize);

                return new Response(json_encode($result, JSON_PRETTY_PRINT), 'application/json', 200);
            } catch (Exception $e) {
                return new Response(json_encode(['error' => $e->getMessage()]), 'application/json', 500);
            }
        }
    ],
    [
        'pattern' => 'imagebank-index-records',
        'method'  => 'GET',
        'action'  => function () {
            $helper = new KirbyInternalHelper();
            if (!$helper->isCurrentUserAdminOrEditor()) {
                return new Response('You must be an administrator to access this page.', 'text/plain', 403);
            }
            try {
                if (!\BSBI\WebBase\helpers\ImageBankIndexHelper::isIndexReady()) {
                    return new Response(json_encode(['rows' => [], 'columns' => [], 'total' => 0, 'page' => 1, 'pageSize' => 20, 'totalPages' => 0]), 'application/json', 200);
                }
                $page     = max(1, (int)kirby()->request()->get('page', 1));
                $pageSize = 20;

                $imageBankIndex = new \BSBI\WebBase\helpers\ImageBankIndexHelper();
                $result         = $imageBankIndex->getRecords($page, $pageSize);

                return new Response(json_encode($result, JSON_PRETTY_PRINT), 'application/json', 200);
            } catch (Exception $e) {
                return new Response(json_encode(['error' => $e->getMessage()]), 'application/json', 500);
            }
        }
    ],
];