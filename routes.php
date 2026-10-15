<?php

use BSBI\WebBase\forms\FormSubmissionExporter;
use BSBI\WebBase\helpers\ContentIndexRegistry;
use BSBI\WebBase\helpers\CsvWriter;
use BSBI\WebBase\helpers\FileArchiveService;
use BSBI\WebBase\helpers\IndexRebuilds;
use BSBI\WebBase\helpers\KirbyInternalHelper;
use BSBI\WebBase\helpers\SearchIndexHelper;
use BSBI\WebBase\helpers\SessionFlash;
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

            $records = [];
            foreach ($page->children()->template('form_submission') as $submission) {
                $records[] = FormSubmissionExporter::record($submission);
            }

            // Row 1: form page title (identification row); then the header row
            // and one row per submission (formula-safe cells; see CsvWriter).
            $csvRows = array_merge(
                [[$page->title()->toString()]],
                (new FormSubmissionExporter())->wide($records, false)
            );

            $csv = CsvWriter::toString($csvRows);

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

            $records = [];
            foreach ($query->getPageIds() as $pageId) {
                $submission = $helper->findKirbyPageOrDraft($pageId);
                if ($submission !== null) {
                    $records[] = FormSubmissionExporter::record($submission);
                }
            }

            // Wide: one row per submission. Long: one row per answer.
            // Formula-safe cells; see CsvWriter.
            $isLong = kirby()->request()->get('format') === 'long';
            $exporter = new FormSubmissionExporter();
            $csvRows = $isLong ? $exporter->long($records) : $exporter->wide($records, true);

            $csv = CsvWriter::toString($csvRows);

            $suffix   = $formTypeFilter !== '' ? '-' . preg_replace('/[^a-z0-9]+/i', '-', $formTypeFilter) : '-all';
            $filename = 'form-submissions' . $suffix . ($isLong ? '-long' : '') . '-' . date('Y-m-d') . '.csv';

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
            $service = new BSBI\WebBase\helpers\ScheduledPublishService(kirby());
            // Prefer the Authorization header (query strings end up in access
            // logs); ?token= stays supported for crons that can only hit a URL.
            $token = null;
            $header = kirby()->request()->header('Authorization');
            if (is_string($header) && str_starts_with($header, 'Bearer ')) {
                $bearer = trim(substr($header, strlen('Bearer ')));
                // An empty Bearer value counts as absent, so a stray empty
                // header can't shadow a correct ?token= fallback.
                if ($bearer !== '') {
                    $token = $bearer;
                }
            }
            if ($token === null) {
                $query = get('token');
                $token = is_string($query) ? $query : null;
            }
            if (!$service->authorise($token)) {
                return new Kirby\Cms\Response('Forbidden', 'text/plain', 403);
            }
            return new Kirby\Cms\Response(
                $service->run(),
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
            // Read-then-clear rather than pull(), which would enter session write
            // mode unconditionally. This is the error route: a second fatal here,
            // from a session commit that races a token regeneration, has nowhere
            // left to go. See SessionFlash.
            $exceptionAsString = (new SessionFlash(kirby()->session()->data()))
                ->pull('exceptionAsString');

            echo Tpl::load(__DIR__ . '/templates/error-500.php', [
                'userRole' => kirby()->user() ? kirby()->user()->role()->name() : '',
                'exception' => $exceptionAsString,
            ]);
            exit();
        },
    ],
    [
        // File Archive permanent URL (bsbi-web#570): stream the file here rather than
        // redirecting to the hashed media URL, so this is the URL that stays in the
        // address bar and gets copied. Byte ranges and 304s are handled inside.
        'pattern' => 'files/(:any)',
        'action'  => function (string $slug) {
            $ifModifiedSince = kirby()->request()->header('If-Modified-Since');
            $response = FileArchiveService::fromKirby(kirby())
                ->respond($slug, is_string($ifModifiedSince) ? $ifModifiedSince : null);

            return $response ?? site()->errorPage();
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
    // the rebuilds are POSTs through the Panel API now (#52); the old GETs refuse
    ...IndexRebuilds::retiredRoutes(),
];
