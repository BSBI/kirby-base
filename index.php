<?php /** @noinspection PhpUnhandledExceptionInspection */

use BSBI\WebBase\helpers\ContentIndexDefinition;
use BSBI\WebBase\helpers\ContentIndexRegistry;
use BSBI\WebBase\helpers\ErrorNotificationThrottle;
use BSBI\WebBase\helpers\FatalError;
use BSBI\WebBase\helpers\FileLinkIndexHelper;
use BSBI\WebBase\helpers\ImageBankIndexHelper;
use BSBI\WebBase\helpers\FilteredFilesHelper;
use BSBI\WebBase\helpers\FilteredPagesHelper;
use BSBI\WebBase\helpers\FormSubmissionIndexDefinition;
use BSBI\WebBase\helpers\SearchIndexHelper;
use BSBI\WebBase\helpers\StyleGuideService;
use BSBI\WebBase\helpers\maintenance\CacheClearTask;
use BSBI\WebBase\helpers\maintenance\LogRetentionTask;
use BSBI\WebBase\helpers\maintenance\MaintenancePanel;
use BSBI\WebBase\helpers\maintenance\MaintenanceRegistry;
use BSBI\WebBase\helpers\maintenance\MediaCleanupTask;
use Kirby\Cms\App as Kirby;
use Kirby\Panel\Ui\Item\PageItem;
use Kirby\Toolkit\I18n;
use Kirby\Toolkit\Tpl;

$pluginConfig = [
    'fields' => [
        'maplocation' => [
            'props' => [
                'value' => function ($value = null) {
                    return \Kirby\Data\Yaml::decode($value);
                },
            ],
        ],
        // A users field whose picker narrows by full name (AND-of-words) instead
        // of Kirby's default OR-of-words search across name/email/role, which is
        // unusable on a site with thousands of users. Behaves identically to the
        // built-in `users` field in every other respect.
        'usernamesearch' => [
            'extends' => 'users',
            'methods' => [
                'userpicker' => function (array $params = []) {
                    $params['model'] = $this->model();
                    return (new \BSBI\WebBase\cms\UserNamePicker($params))->toArray();
                },
            ],
        ],
    ],
    'blueprints' => require __DIR__ . '/blueprints.php',
    'snippets' => require __DIR__ . '/snippets.php',
    'hooks' => require __DIR__ . '/hooks.php',
    'routes' => require __DIR__ . '/routes.php',
    'templates' => [
        'file_link' => __DIR__ . '/templates/file_link.php',
        'form_submission' => __DIR__ . '/templates/form_submission.php',
        'page_link' => __DIR__ . '/templates/page_link.php',
        'emails/form-notification.html' => __DIR__ . '/templates/emails/form-notification.html.php',
        'emails/form-notification.text' => __DIR__ . '/templates/emails/form-notification.text.php',
        'search_log' => __DIR__ . '/templates/search_log.php',
        'search_log_item' => __DIR__ . '/templates/search_log_item.php',
    ],
    'controllers' => [
        'image_bank' =>  require __DIR__ . '/controllers/image_bank.php',
        'file_link' =>  require __DIR__ . '/controllers/file_link.php',
        'page_link' =>  require __DIR__ . '/controllers/page_link.php',
    ],
    'collections' => [
        'formSubmissions' => require __DIR__ . '/collections/formSubmissions.php',
    ],
    'sections' => [
        'formsubmissionexport' => require __DIR__ . '/sections/formsubmissionexport.php',
        'formsubmissionsindex' => require __DIR__ . '/sections/formsubmissionsindex.php',
        'quicklinks' => require __DIR__ . '/sections/quicklinks.php',
        'searchanalytics' => require __DIR__ . '/sections/searchanalytics.php',
        'searchindexstats' => require __DIR__ . '/sections/searchindexstats.php',
        'contentindexstats'   => require __DIR__ . '/sections/contentindexstats.php',
        'imagebankindexstats' => require __DIR__ . '/sections/imagebankindexstats.php',
        'translatedpages' => require __DIR__ . '/sections/translatedpages.php',
        'filteredpages'   => require __DIR__ . '/sections/filteredpages.php',
        'filteredfiles'   => require __DIR__ . '/sections/filteredfiles.php',
        'filearchivelinks' => require __DIR__ . '/sections/filearchivelinks.php',
        'styleguidecheck' => require __DIR__ . '/sections/styleguidecheck.php',
    ],
    'api' => [
        'routes' => [
            [
                'pattern' => 'filtered-files/options',
                'method'  => 'GET',
                'action'  => function (): array {
                    $params = FilteredFilesHelper::parseOptionsParams();
                    return FilteredFilesHelper::getOptions($params['filterDefs'], $params['modelId']);
                },
            ],
            [
                'pattern' => 'filtered-files/results',
                'method'  => 'GET',
                'action'  => function (): array {
                    $p = FilteredFilesHelper::parseResultsParams();
                    return FilteredFilesHelper::getResults(
                        $p['modelId'],
                        $p['filterDefs'],
                        $p['columnDefs'],
                        $p['active'],
                        $p['search'],
                        $p['sortField'],
                        $p['sortDir'],
                        $p['page'],
                        $p['pageSize']
                    );
                },
            ],
            [
                'pattern' => 'filtered-pages/options',
                'method'  => 'GET',
                'action'  => function (): array {
                    $filterDefs = json_decode(get('filters', '{}'), true) ?? [];
                    $modelId    = (string)get('model_id', '');
                    $template   = (string)get('template', '');
                    return FilteredPagesHelper::getOptions($filterDefs, $modelId, $template);
                },
            ],
            [
                'pattern' => 'style-guide/check',
                'method'  => 'POST',
                /**
                 * Check the given page's content against the style guide via Gemini.
                 *
                 * @return array{report: string}|array{error: string}
                 */
                'action'  => function (): array {
                    return StyleGuideService::check((string) get('pageId', ''));
                },
            ],
            [
                'pattern' => 'filtered-pages/results',
                'method'  => 'GET',
                'action'  => function (): array {
                    $modelId    = (string)get('model_id', '');
                    $template   = (string)get('template', '');
                    $filterDefs = json_decode(get('filters', '{}'), true) ?? [];
                    $columnDefs = json_decode(get('columns', '[]'), true) ?? [];
                    $active     = json_decode(get('active', '{}'), true) ?? [];
                    $search     = (string)get('search', '');
                    $sortParts  = explode(' ', (string)get('sort', 'title asc'), 2);
                    $sortField  = $sortParts[0] ?? 'title';
                    $sortDir    = strtolower($sortParts[1] ?? 'asc') === 'desc' ? 'desc' : 'asc';
                    $page       = max(1, (int)get('page', 1));
                    $pageSize   = max(1, min(200, (int)get('page_size', 25)));

                    return FilteredPagesHelper::getResults(
                        $modelId,
                        $template,
                        $filterDefs,
                        $columnDefs,
                        $active,
                        $search,
                        $sortField,
                        $sortDir,
                        $page,
                        $pageSize
                    );
                },
            ],
        ],
    ],
];

// Override panel page search with fast SQLite-backed search when enabled
if (option('search.panelSearch', false)) {
    $pluginConfig['areas'] = [
        'site' => function () {
            return [
                'searches' => [
                    'pages' => [
                        'label' => I18n::translate('pages'),
                        'icon'  => 'page',
                        'query' => function (string|null $query, int $limit, int $page) {
                            if (empty($query)) {
                                return ['results' => [], 'pagination' => null];
                            }

                            try {
                                $searchIndex = new SearchIndexHelper();
                                $offset = ($page - 1) * $limit;
                                $searchResult = $searchIndex->searchAllPages($query, $limit, $offset);

                                $pageIds = $searchResult['results'];
                                $total = $searchResult['total'];

                                if (empty($pageIds)) {
                                    return ['results' => [], 'pagination' => null];
                                }

                                // Load Kirby page objects and filter to listable
                                $pages = pages($pageIds)->filter('isListable', true);

                                $results = $pages->values(
                                    fn ($p) => (new PageItem(page: $p, info: '{{ page.id }}'))->props()
                                );

                                return [
                                    'results'    => $results,
                                    'pagination' => [
                                        'page'   => $page,
                                        'total'  => $total,
                                        'limit'  => $limit,
                                        'pages'  => (int)ceil($total / $limit),
                                        'offset' => $offset,
                                    ]
                                ];
                            } catch (Throwable $e) {
                                error_log('Panel search failed, falling back to default: ' . $e->getMessage());

                                // Fall back to default Kirby panel search
                                return \Kirby\Panel\Controller\Search::pages($query, $limit, $page);
                            }
                        }
                    ]
                ]
            ];
        }
    ];
}

// Index stats panel area — opt-in via contentIndex.showIndexStatsPanel config
if (option('contentIndex.showIndexStatsPanel', false)) {
    if (!array_key_exists('areas', $pluginConfig)) {
        $pluginConfig['areas'] = [];
    }
    $pluginConfig['areas']['index-stats'] = function () {
        return [
            'label' => 'Indexes',
            'icon'  => 'chart',
            'menu'  => true,
            'link'  => 'index-stats',
            'views' => [
                [
                    'pattern' => 'index-stats',
                    'action'  => function () {
                        $searchStats = null;
                        try {
                            $searchHelper = new SearchIndexHelper();
                            $searchStats  = $searchHelper->getStats();
                        } catch (Throwable) {
                        }

                        $contentIndexes = [];
                        try {
                            foreach (ContentIndexRegistry::all() as $manager) {
                                $contentIndexes[] = $manager->getStats();
                            }
                        } catch (Throwable) {
                        }

                        $imageBankStats = null;
                        try {
                            if (ImageBankIndexHelper::isIndexReady()) {
                                $imageBankHelper = new ImageBankIndexHelper();
                                $imageBankStats  = $imageBankHelper->getStats();
                            }
                        } catch (Throwable) {
                        }

                        $fileLinkStats = null;
                        try {
                            if (FileLinkIndexHelper::isIndexReady()) {
                                $fileLinkHelper = new FileLinkIndexHelper();
                                $fileLinkStats  = $fileLinkHelper->getStats();
                            }
                        } catch (Throwable) {
                        }

                        return [
                            'component' => 'k-index-stats-view',
                            'title'     => 'Indexes',
                            'props'     => [
                                'searchStats'    => $searchStats,
                                'contentIndexes' => $contentIndexes,
                                'imageBankStats' => $imageBankStats,
                                'fileLinkStats'  => $fileLinkStats,
                            ],
                        ];
                    },
                ],
            ],
        ];
    };
}

// Maintenance panel area — opt-in via maintenance.showPanel config. Provides a live-safe
// "Reclaim disk" dashboard (dry-run preview → confirm → run) for the registered tasks.
if (option('maintenance.showPanel', false)) {
    if (!array_key_exists('areas', $pluginConfig)) {
        $pluginConfig['areas'] = [];
    }
    $pluginConfig['areas']['maintenance'] = function () {
        return [
            'label' => 'Maintenance',
            'icon'  => 'trash',
            'menu'  => true,
            'link'  => 'maintenance',
            'views' => [
                [
                    'pattern' => 'maintenance',
                    'action'  => function () {
                        return [
                            'component' => 'k-maintenance-view',
                            'title'     => 'Maintenance',
                            'props'     => MaintenancePanel::dashboardProps(kirby()),
                        ];
                    },
                ],
            ],
        ];
    };

    // $pluginConfig always defines api.routes (above), so append directly.
    $pluginConfig['api']['routes'][] = [
        'pattern' => 'maintenance/dashboard',
        'method'  => 'GET',
        'action'  => function () {
            return MaintenancePanel::dashboardProps(kirby());
        },
    ];
    $pluginConfig['api']['routes'][] = [
        'pattern' => 'maintenance/preview',
        'method'  => 'GET',
        'action'  => function () {
            return MaintenancePanel::previewOne(kirby());
        },
    ];
    $pluginConfig['api']['routes'][] = [
        'pattern' => 'maintenance/run',
        'method'  => 'POST',
        'action'  => function () {
            return MaintenancePanel::run(kirby());
        },
    ];
}

Kirby::plugin('open-foundations/kirby-base', $pluginConfig);

// Register the generic maintenance tasks (site-specific ones register themselves).
if (option('maintenance.showPanel', false)) {
    try {
        MaintenanceRegistry::register(new LogRetentionTask(kirby()));
        MaintenanceRegistry::register(new CacheClearTask(kirby()));
        MaintenanceRegistry::register(new MediaCleanupTask(kirby()));
    } catch (Throwable $e) {
        error_log('Failed to register maintenance tasks: ' . $e->getMessage());
    }
}

// Register built-in form submissions index
try {
    ContentIndexRegistry::register(new FormSubmissionIndexDefinition());
} catch (Throwable $e) {
    error_log('Failed to register form submissions content index: ' . $e->getMessage());
}

// Register content indexes from site configuration
$contentIndexes = option('contentIndexes', []);
foreach ($contentIndexes as $definition) {
    if ($definition instanceof ContentIndexDefinition) {
        try {
            ContentIndexRegistry::register($definition);
        } catch (Throwable $e) {
            error_log('Failed to register content index "' . $definition->getName() . '": ' . $e->getMessage());
        }
    }
}

if (option('debug') === false) {
    /**
     * Sends an alert to the site admin, at most once per throttle window per distinct
     * fault.
     *
     * Un-throttled, a site-wide fault alerts once per request: every visitor hit
     * generates an email. That is enough to get the sending domain rate-limited, which
     * takes out the site's transactional mail (membership, payments, password resets)
     * along with the alert channel itself — the alerting amplifies the outage it exists
     * to report.
     *
     * @param string $fingerprint stable identifier for the fault, so distinct faults
     *                            each alert rather than the first masking the rest
     * @param string $subject email subject
     * @param string $bodyHtml email body
     */
    $notifyAdmin = static function (string $fingerprint, string $subject, string $bodyHtml): void {
        if (str_starts_with((string) ($_SERVER['HTTP_HOST'] ?? ''), 'localhost')) {
            return;
        }

        try {
            $window = option('errorNotificationWindowSeconds', ErrorNotificationThrottle::DEFAULT_WINDOW_SECONDS);

            $throttle = new ErrorNotificationThrottle(
                kirby()->root('logs') . '/error-throttle',
                is_numeric($window) ? (int) $window : ErrorNotificationThrottle::DEFAULT_WINDOW_SECONDS
            );

            if ($throttle->shouldNotify($fingerprint) === false) {
                return;
            }

            kirby()->email([
                'to' => option('adminEmail'),
                'from' => option('defaultEmail'),
                'subject' => $subject,
                'body' => ['html' => $bodyHtml],
            ]);
        } catch (Throwable) {
            // alerting must never break error handling
        }
    };

    // Set by the exception handler so the shutdown handler below does not report the
    // same failure a second time.
    $errorReported = false;

    // Set a global exception handler
    set_exception_handler(function (Throwable $exception) use ($notifyAdmin, &$errorReported) {
        $redirectUrl = $_SERVER['REDIRECT_URL'] ?? '';
        $pageUrl = is_string($redirectUrl) ? $redirectUrl : '';
        $exceptionAsString = "Message: " . $exception->getMessage() . "\n" .
            "File:" . $exception->getFile() . "'\n" .
            "Line:" . $exception->getLine() . "\n" .
            "Trace:" . $exception->getTraceAsString() . "\n" .
            "Page: " . $pageUrl . "\n";

        error_log($exceptionAsString);

        $notifyAdmin(
            $exception->getMessage() . '|' . $exception->getFile() . '|' . $exception->getLine(),
            'Website Exception: ' . $exception->getMessage(),
            "<b>An unhandled exception occurred:</b><br>" .
                "<b>Message</b>: " . htmlspecialchars($exception->getMessage()) . "<br>" .
                "<b>File:</b> " . htmlspecialchars($exception->getFile()) . "<br>" .
                "<b>Line:</b> " . $exception->getLine() . "<br>" .
                "<b>Trace:</b> " . htmlspecialchars($exception->getTraceAsString()) . "<br>" .
                "<b>Page:</b> " . htmlspecialchars($pageUrl)
        );

        // Render the error page and pass the exception
        $kirby = Kirby::instance();

        // Send the status line directly. This handler bypasses Kirby's normal
        // render/send flow, and Responder::code() only records the code for that flow
        // to apply later — so on its own it leaves the error page being served as 200,
        // which reports a dead site as healthy to uptime monitoring.
        if (headers_sent() === false) {
            http_response_code(500);
        }

        $kirby->response()->code(500); // keep the Responder in step for anything reading it

        // Only now mark the failure as reported. Doing it earlier would mean a fault in
        // the error page itself (below) is swallowed by the shutdown handler, which is
        // the exact silent-failure this handling exists to prevent.
        $errorReported = true;

        try {
            echo Tpl::load(__DIR__ . '/templates/error-500.php', [
                'userRole' => $kirby->user() ? $kirby->user()->role()->name() : '',
                'exception' => $exceptionAsString,
            ]);
        } catch (Throwable $renderFailure) {
            error_log('Error page failed to render: ' . $renderFailure->getMessage());
            $notifyAdmin(
                'error-page|' . $renderFailure->getMessage(),
                'Website error page failed to render',
                '<b>The error page itself failed to render:</b><br>' .
                    htmlspecialchars($renderFailure->getMessage())
            );
            echo 'A server error occurred.';
        }

        exit;
    });

    /**
     * Fatal errors — out of memory, parse errors, E_USER_ERROR — never reach
     * set_exception_handler. Without this they produce a blank or truncated page with no
     * log entry and no alert, making them the failures least likely to be noticed.
     */
    register_shutdown_function(function () use ($notifyAdmin, &$errorReported): void {
        if ($errorReported === true) {
            return;
        }

        $error = FatalError::fromLastError(error_get_last());

        if ($error === null) {
            return;
        }

        $redirectUrl = $_SERVER['REDIRECT_URL'] ?? '';
        $pageUrl = is_string($redirectUrl) ? $redirectUrl : '';
        $errorAsString = $error->describe($pageUrl);

        error_log($errorAsString);

        // Log and alert before attempting to render: an out-of-memory fatal may not
        // leave enough headroom to load a template, and the alert matters more.
        $notifyAdmin(
            $error->fingerprint(),
            'Website Fatal Error: ' . $error->message,
            "<b>A fatal error occurred:</b><br>" .
                "<b>Message</b>: " . htmlspecialchars($error->message) . "<br>" .
                "<b>File:</b> " . htmlspecialchars($error->file) . "<br>" .
                "<b>Line:</b> " . $error->line . "<br>" .
                "<b>Page:</b> " . htmlspecialchars($pageUrl)
        );

        // If output has already started the response cannot be salvaged — the visitor
        // gets truncated HTML and the status line is long gone. Only a keyword-based
        // uptime check will see this case; the log and alert above are all we can do.
        if (headers_sent() === true) {
            return;
        }

        http_response_code(500);

        try {
            echo Tpl::load(__DIR__ . '/templates/error-500.php', [
                'userRole' => '',
                'exception' => $errorAsString,
            ]);
        } catch (Throwable) {
            echo 'A server error occurred.';
        }
    });
}
