<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

use Kirby\Exception\Exception as KirbyException;
use Kirby\Exception\NotFoundException;
use Kirby\Exception\PermissionException;
use Kirby\Http\Response;
use Throwable;

/**
 * The Panel's index rebuilds: content indexes, search, image bank and file links.
 *
 * Each is a POST through Kirby's API, so Kirby checks the session, Panel access and
 * the CSRF token before the action runs, and the action itself admits admins and
 * editors only. They were GET routes until #52, so a link followed by a signed-in
 * admin — in an email, or another site — could start one.
 *
 * The old GET URLs stay registered to refuse with a 405, rather than fall through to
 * page routing and answer with a confusing 404.
 */
final class IndexRebuilds
{
    /**
     * The rebuilds, for the plugin's `api.routes`.
     *
     * @return list<array{pattern: string, method: string, action: callable}>
     */
    public static function apiRoutes(): array
    {
        // IndexRebuilds::, never self::. Kirby's API runs an action with Closure::call,
        // which moves self:: to its own Api class, where these names mean something else.
        return [
            [
                // body: {name} for one index; without it, every index
                'pattern' => 'indexes/rebuild/content',
                'method'  => 'POST',
                'action'  => fn (): array => IndexRebuilds::content((string)(kirby()->request()->get('name') ?? '')),
            ],
            [
                'pattern' => 'indexes/rebuild/search',
                'method'  => 'POST',
                'action'  => fn (): array => IndexRebuilds::search(),
            ],
            [
                'pattern' => 'indexes/rebuild/imagebank',
                'method'  => 'POST',
                'action'  => fn (): array => IndexRebuilds::imageBank(),
            ],
            [
                'pattern' => 'indexes/rebuild/filelinks',
                'method'  => 'POST',
                'action'  => fn (): array => IndexRebuilds::fileLinks(),
            ],
        ];
    }

    /**
     * The GET URLs the rebuilds used to answer, for the plugin's `routes`: each refuses.
     *
     * @return list<array{pattern: string, method: string, action: callable(): Response}>
     */
    public static function retiredRoutes(): array
    {
        // not static: Kirby's router binds every route action, and a static closure refuses
        $refuse = fn (): Response => new Response(
            'Index rebuilds are started from the Panel\'s Indexes page, as a POST to the Panel API.',
            'text/plain',
            405,
            ['Allow' => 'POST']
        );

        return array_map(
            static fn (string $pattern): array => ['pattern' => $pattern, 'method' => 'GET', 'action' => $refuse],
            ['content-index-rebuild', 'search-rebuild', 'imagebank-index-rebuild', 'filelinks-index-rebuild']
        );
    }

    /**
     * Rebuild one content index, or all of them.
     *
     * @param string $name the index, or '' for every registered index
     * @return array{message: string}
     * @throws PermissionException|NotFoundException|KirbyException
     */
    public static function content(string $name): array
    {
        $helper = self::adminOrEditorHelper();

        // Resolve the targets up front, so an unknown name is a clean 404
        if ($name !== '') {
            $manager = ContentIndexRegistry::get($name);
            if ($manager === null) {
                throw new NotFoundException(['fallback' => "Content index '$name' not found."]);
            }
            $managers = [$name => $manager];
        } else {
            $managers = ContentIndexRegistry::all();
        }

        self::liftLimits();

        $message = "Content index rebuild complete:\n";
        try {
            foreach ($managers as $indexName => $manager) {
                $count = $manager->rebuildIndex($helper);
                $message .= "  $indexName: $count pages indexed\n";
            }
        } catch (Throwable $e) {
            // another writer held the index past SQLite's wait
            if (stripos($e->getMessage(), 'database is locked') !== false) {
                throw new KirbyException([
                    'httpCode' => 409,
                    'fallback' => 'The index is busy, probably with another rebuild. Try again in a minute.',
                ]);
            }
            throw self::failed('content index', $e);
        }

        return ['message' => $message];
    }

    /**
     * Rebuild the search index.
     *
     * @return array{message: string}
     * @throws PermissionException|KirbyException
     */
    public static function search(): array
    {
        self::adminOrEditorHelper();
        self::liftLimits();

        try {
            $result = (new SearchIndexHelper())->rebuildIndex();
        } catch (Throwable $e) {
            throw self::failed('search index', $e);
        }

        $message = "Search index rebuilt. Indexed {$result['search_index']} pages for site search";
        $message .= $result['all_pages'] === null
            ? '.'
            : ", {$result['all_pages']} pages for Panel search.";
        if ($result['used_fallback']) {
            $message .= ' There is no allPages collection, so every listed and unlisted page was indexed.';
        }

        return ['message' => $message];
    }

    /**
     * Rebuild the image bank index.
     *
     * @return array{message: string}
     * @throws PermissionException|KirbyException
     */
    public static function imageBank(): array
    {
        self::adminOrEditorHelper();
        self::liftLimits();

        try {
            $count = (new ImageBankIndexHelper())->rebuildIndex();
        } catch (Throwable $e) {
            throw self::failed('imageBank index', $e);
        }

        return ['message' => "imageBank index rebuilt. $count files indexed."];
    }

    /**
     * Rebuild the file-link index.
     *
     * @return array{message: string}
     * @throws PermissionException|KirbyException
     */
    public static function fileLinks(): array
    {
        self::adminOrEditorHelper();
        self::liftLimits();

        try {
            $count = (new FileLinkIndexHelper())->rebuildIndex();
        } catch (Throwable $e) {
            throw self::failed('file-link index', $e);
        }

        return ['message' => "File-link index rebuilt. $count pages with links indexed."];
    }

    /**
     * A failed rebuild, as the Panel sees it: the detail goes to the server's error log,
     * not to the browser, since a PDO or filesystem message can carry paths and schema.
     */
    private static function failed(string $what, Throwable $e): KirbyException
    {
        error_log("Index rebuild: failed to rebuild the $what: " . $e->getMessage());

        return new KirbyException([
            'httpCode' => 500,
            'fallback' => "Failed to rebuild the $what. The server's error log has the detail.",
        ]);
    }

    /**
     * The helper the rebuilds need, once the user is known to be an admin or editor.
     *
     * @throws PermissionException for anyone else
     */
    private static function adminOrEditorHelper(): KirbyInternalHelper
    {
        $helper = new KirbyInternalHelper();
        if (!$helper->isCurrentUserAdminOrEditor()) {
            throw new PermissionException(['fallback' => 'Only an administrator or editor can rebuild an index.']);
        }

        return $helper;
    }

    /**
     * A rebuild of a large index runs well past PHP's default 30s and 256M, and being
     * killed part way leaves it unfinished. It stays a blocking request, so the Panel's
     * call resolves only once the rebuild is done and the reloaded stats are new.
     */
    private static function liftLimits(): void
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');
    }
}
