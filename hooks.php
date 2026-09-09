<?php

use BSBI\WebBase\helpers\ContentIndexRegistry;
use BSBI\WebBase\helpers\FileArchiveService;
use BSBI\WebBase\helpers\FileLinkIndexHelper;
use BSBI\WebBase\helpers\ImageConversionHelper;
use BSBI\WebBase\helpers\KirbyBaseHelper;
use BSBI\WebBase\helpers\KirbyInternalHelper;
use BSBI\WebBase\helpers\SearchIndexHelper;
use BSBI\WebBase\helpers\UuidResolver;
use Kirby\Filesystem\F;

/**
 * Re-index the file links contained in a page's content.
 *
 * No-op until the file-link index has been built. Best-effort: failures are
 * logged but never block the page operation.
 *
 * @param Kirby\Cms\Page $page      The page to index.
 * @param string|null    $oldPageId Previous page ID, when it has changed (slug rename / move).
 * @return void
 */
function updateFileLinkIndex(Kirby\Cms\Page $page, ?string $oldPageId = null): void
{
    if (!FileLinkIndexHelper::isIndexReady()) {
        return;
    }
    try {
        $index = new FileLinkIndexHelper();
        if ($oldPageId !== null && $oldPageId !== $page->id()) {
            $index->removePage($oldPageId);
        }
        $index->indexPage($page);
    } catch (Throwable $e) {
        KirbyBaseHelper::writeToLogFile(
            'file-link-index',
            'Failed to update file-link index for page ' . $page->id() . ': ' . $e->getMessage()
        );
    }
}

/**
 * Remove a page from the file-link index.
 *
 * No-op until the file-link index has been built. Best-effort: failures are logged.
 *
 * @param string $pageId The page ID to remove.
 * @return void
 */
function removeFromFileLinkIndex(string $pageId): void
{
    if (!FileLinkIndexHelper::isIndexReady()) {
        return;
    }
    try {
        (new FileLinkIndexHelper())->removePage($pageId);
    } catch (Throwable $e) {
        KirbyBaseHelper::writeToLogFile(
            'file-link-index',
            'Failed to remove page from file-link index for page ' . $pageId . ': ' . $e->getMessage()
        );
    }
}

function handlePageChange($newPage, $oldPage) {
    static $isHandling = false;

    if ($isHandling) {
        return $newPage;
    }

    $isHandling = true;

    try {
        $user = kirby()->user();

        // Each of these is best-effort: a failure here must not prevent the
        // reindexing below from running, and must not vanish silently either
        // (bsbi-web#725 — an uncaught exception here used to abort the whole
        // hook before the index was ever touched, with nothing logged).
        try {
            $newPage = $newPage->update([
                'updatedDate' => date('Y-m-d H:i:s'),
                'updatedBy' => $user?->id()
            ]);

            if ($newPage->publishedDate()->isEmpty()) {
                $newPage = $newPage->update([
                    'publishedDate' => date('Y-m-d H:i:s'),
                    'publishedBy' => $user?->id()
                ]);
            }
        } catch (Throwable $e) {
            KirbyBaseHelper::writeToLogFile('search-index', 'Failed to stamp updatedDate/publishedDate for page ' . $newPage->id() . ': ' . $e->getMessage());
        }

        $helper = new KirbyInternalHelper();

        try {
            $helper->handleTwoWayTagging($newPage, $oldPage);
        } catch (Throwable $e) {
            KirbyBaseHelper::writeToLogFile('search-index', 'Failed two-way tagging for page ' . $newPage->id() . ': ' . $e->getMessage());
        }

        try {
            $helper->handleCaches($newPage);
        } catch (Throwable $e) {
            KirbyBaseHelper::writeToLogFile('search-index', 'Failed cache handling for page ' . $newPage->id() . ': ' . $e->getMessage());
        }

        // Update search index
        try {
            $searchIndex = new SearchIndexHelper();
            // If the page ID changed (e.g. slug rename), remove the stale old entry first
            if ($oldPage !== null && $oldPage->id() !== $newPage->id()) {
                $searchIndex->removePage($oldPage->id());
            }
            $searchIndex->indexPage($newPage);
        } catch (Throwable $e) {
            KirbyBaseHelper::writeToLogFile('search-index', 'Failed to update search index for page ' . $newPage->id() . ': ' . $e->getMessage());
        }

        // Update content indexes
        try {
            $managers = ContentIndexRegistry::getManagersForTemplate($newPage->intendedTemplate()->name());
            foreach ($managers as $manager) {
                // If the page ID changed (e.g. slug rename), remove the stale old entry first
                if ($oldPage !== null && $oldPage->id() !== $newPage->id()) {
                    $manager->removePage($oldPage->id());
                }
                $manager->indexPage($newPage, $helper);
            }
        } catch (Throwable $e) {
            KirbyBaseHelper::writeToLogFile('search-index', 'Failed to update content index for page ' . $newPage->id() . ': ' . $e->getMessage());
        }

        // Update file-link (reverse-link) index
        updateFileLinkIndex($newPage, $oldPage?->id());

        return $newPage;
    } finally {
        $isHandling = false;
    }
}

/**
 * Keeps the site's scheduled-publication queue in step with a page's own
 * scheduledPublishDate/Time fields (present when its blueprint includes the
 * Info tab or the scheduled-publish field group).
 *
 * Best-effort: a queue failure is logged and never blocks the editor's save.
 *
 * @param Kirby\Cms\Page $page The page as saved.
 * @return void
 */
function syncScheduledPublishQueue(Kirby\Cms\Page $page): void
{
    try {
        $content = $page->content();
        // keys() are lowercase (Kirby normalises content keys); get() below is
        // case-insensitive, so the camelCase names still resolve.
        if (!in_array('scheduledpublishdate', $content->keys(), true)) {
            return;
        }

        // Scheduling causes publication, so it demands the same permission:
        // a user who cannot change the page's status must not be able to
        // touch its queue entry. (The cron's own field-clearing updates run
        // impersonated as kirby, which always passes — this hook fires
        // synchronously inside that update call, within the impersonation
        // closure's scope.)
        if (!$page->permissions()->can('changeStatus')) {
            KirbyBaseHelper::writeToLogFile(
                'scheduledPublish',
                'Ignored schedule fields on ' . $page->id() . ': user lacks changeStatus permission'
            );
            return;
        }

        $service = new BSBI\WebBase\helpers\ScheduledPublishService(kirby());
        $dateField = $content->get('scheduledPublishDate');
        $date = $dateField instanceof Kirby\Content\Field ? trim($dateField->toString()) : '';

        if ($date === '' || $page->isListed()) {
            $service->remove($page);
            return;
        }

        $timeField = $content->get('scheduledPublishTime');
        $time = $timeField instanceof Kirby\Content\Field ? trim($timeField->toString()) : '';
        $service->queue($page, $date, $time);
    } catch (Throwable $e) {
        KirbyBaseHelper::writeToLogFile(
            'scheduledPublish',
            'Failed to sync scheduled-publish queue for page ' . $page->id() . ': ' . $e->getMessage()
        );
    }
}

/**
 * Drops UuidResolver's remembered misses so a file or page that has just been
 * created, renamed or moved resolves at once (bsbi-web#732). Best-effort.
 */
function forgetUuidMisses(string $context): void
{
    try {
        UuidResolver::instance()->forgetMisses();
    } catch (Throwable $e) {
        KirbyBaseHelper::writeToLogFile('uuid-misses', 'Failed to clear the miss list after ' . $context . ': ' . $e->getMessage());
    }
}

return [
    'file.create:after' => function (Kirby\Cms\File $file) {
        forgetUuidMisses('file.create');
        $filename = $file->filename();
        if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'bmp') {
            return $file;
        }

        if (!ImageConversionHelper::canConvertBmp()) {
            KirbyBaseHelper::writeToLogFile(
                'bmp-convert',
                'No image library available to convert BMP file: ' . $filename
            );
            return $file;
        }

        $tmpPath = null;
        try {
            $tmpPath = ImageConversionHelper::convertBmpToPng($file->root());

            // Overwrite the BMP file on disk with the PNG data
            F::copy($tmpPath, $file->root(), true);

            // Rename the Kirby file from .bmp to .png
            $baseName = pathinfo($filename, PATHINFO_FILENAME);
            return $file->changeName($baseName, false, 'png');
        } catch (Throwable $e) {
            KirbyBaseHelper::writeToLogFile(
                'bmp-convert',
                'Failed to convert BMP to PNG for ' . $filename . ': ' . $e->getMessage()
            );
            return $file;
        } finally {
            if ($tmpPath !== null && file_exists($tmpPath)) {
                unlink($tmpPath);
            }
        }
    },

    // A File Archive permanent URL must be URL-safe and unique: the route matches
    // exactly, so a bad or duplicated slug is a broken link (bsbi-web#570).
    'file.update:before' => function (Kirby\Cms\File $file, array $values, array $strings) {
        $slug = FileArchiveService::slugFromValues($values);
        if ($slug === null) {
            return;
        }
        $archive = FileArchiveService::fromKirby(kirby());
        if ($archive->isArchiveFile($file)) {
            $archive->validateSlug($slug, $file);
        }
    },

    'file.changeName:after' => function (Kirby\Cms\File $newFile, Kirby\Cms\File $oldFile) {
        forgetUuidMisses('file.changeName');
        return $newFile;
    },

    'page.duplicate:after' => function (Kirby\Cms\Page $duplicatePage, Kirby\Cms\Page $originalPage) {
        forgetUuidMisses('page.duplicate');
        return $duplicatePage;
    },

    'page.update:after' => function ($newPage, $oldPage) {
        $result = handlePageChange($newPage, $oldPage);
        syncScheduledPublishQueue($result instanceof Kirby\Cms\Page ? $result : $newPage);
        return $result;
    },

    'page.changeTitle:after' => function ($newPage, $oldPage) {
        return handlePageChange($newPage, $oldPage);
    },

    'page.changeSlug:after' => function ($newPage, $oldPage) {
        forgetUuidMisses('page.changeSlug');
        return handlePageChange($newPage, $oldPage);
    },


    'page.create:after' => function ($page) {
        forgetUuidMisses('page.create');
        // Each of these is best-effort: a failure here must not prevent the
        // indexing below from running, and must not vanish silently either
        // (bsbi-web#725 — an uncaught exception here used to abort the whole
        // hook before the index was ever touched, with nothing logged).
        try {
            if ($page->publishedDate()->isEmpty() || $page->publishedBy()->isEmpty()) {
                $user = kirby()->user();
                $page = $page->update([
                    'publishedDate' => date('Y-m-d H:i:s'),
                    'publishedBy' => $user?->id()
                ]);
            }
        } catch (Throwable $e) {
            KirbyBaseHelper::writeToLogFile('search-index', 'Failed to backfill publishedDate/publishedBy for page ' . $page->id() . ': ' . $e->getMessage());
        }

        $helper = new KirbyInternalHelper();

        try {
            $helper->handleTwoWayTagging($page);
        } catch (Throwable $e) {
            KirbyBaseHelper::writeToLogFile('search-index', 'Failed two-way tagging for page ' . $page->id() . ': ' . $e->getMessage());
        }

        try {
            $helper->handleCaches($page);
        } catch (Throwable $e) {
            KirbyBaseHelper::writeToLogFile('search-index', 'Failed cache handling for page ' . $page->id() . ': ' . $e->getMessage());
        }

        // Add to search index
        try {
            $searchIndex = new SearchIndexHelper();
            $searchIndex->indexPage($page);
        } catch (Throwable $e) {
            KirbyBaseHelper::writeToLogFile('search-index', 'Failed to add page to search index for page ' . $page->id() . ': ' . $e->getMessage());
        }

        // Add to content indexes
        try {
            $managers = ContentIndexRegistry::getManagersForTemplate($page->intendedTemplate()->name());
            foreach ($managers as $manager) {
                $manager->indexPage($page, $helper);
            }
        } catch (Throwable $e) {
            KirbyBaseHelper::writeToLogFile('search-index', 'Failed to add page to content index for page ' . $page->id() . ': ' . $e->getMessage());
        }

        // Add to file-link (reverse-link) index
        updateFileLinkIndex($page);

        return $page;
    },

    'page.changeStatus:after' => function ($newPage, $_oldPage) {
        $helper = new KirbyInternalHelper();

        // Best-effort: a failure here must not prevent the reindexing below
        // (bsbi-web#725), and must be logged rather than vanish silently.
        try {
            $helper->handleCaches($newPage);
        } catch (Throwable $e) {
            KirbyBaseHelper::writeToLogFile('search-index', 'Failed cache handling after status change for page ' . $newPage->id() . ': ' . $e->getMessage());
        }

        // Update search index
        try {
            $searchIndex = new SearchIndexHelper();
            $searchIndex->indexPage($newPage);
        } catch (Throwable $e) {
            KirbyBaseHelper::writeToLogFile('search-index', 'Failed to update search index after status change for page ' . $newPage->id() . ': ' . $e->getMessage());
        }

        // Update content indexes
        try {
            $managers = ContentIndexRegistry::getManagersForTemplate($newPage->intendedTemplate()->name());
            foreach ($managers as $manager) {
                $manager->indexPage($newPage, $helper);
            }
        } catch (Throwable $e) {
            KirbyBaseHelper::writeToLogFile('search-index', 'Failed to update content index after status change for page ' . $newPage->id() . ': ' . $e->getMessage());
        }

        // Update file-link (reverse-link) index
        updateFileLinkIndex($newPage);
    },
    'page.delete:before' => function (Kirby\Cms\Page $page) {
        // Cache clearing is best-effort — must not block index removal if it fails
        try {
            $helper = new KirbyInternalHelper();
            $helper->handleCaches($page);
        } catch (Throwable $e) {
            KirbyBaseHelper::writeToLogFile('search-index', 'Failed to clear caches for deleted page ' . $page->id() . ': ' . $e->getMessage());
        }

        // Remove from search index
        try {
            $searchIndex = new SearchIndexHelper();
            $searchIndex->removePage($page->id());
        } catch (Throwable $e) {
            KirbyBaseHelper::writeToLogFile('search-index', 'Failed to remove page from search index for page ' . $page->id() . ': ' . $e->getMessage());
        }

        // Remove from content indexes
        try {
            $managers = ContentIndexRegistry::getManagersForTemplate($page->intendedTemplate()->name());
            foreach ($managers as $manager) {
                $manager->removePage($page->id());
            }
        } catch (Throwable $e) {
            KirbyBaseHelper::writeToLogFile('search-index', 'Failed to remove page from content index for page ' . $page->id() . ': ' . $e->getMessage());
        }

        // Remove from file-link (reverse-link) index
        removeFromFileLinkIndex($page->id());

        // Remove any scheduled-publication queue entry, so the queue never
        // holds a dangling reference (unresolvable entries are kept forever
        // by design — see ScheduledPublishService).
        try {
            (new BSBI\WebBase\helpers\ScheduledPublishService(kirby()))->remove($page);
        } catch (Throwable $e) {
            KirbyBaseHelper::writeToLogFile(
                'scheduledPublish',
                'Failed to remove deleted page from scheduled-publish queue: ' . $page->id() . ': ' . $e->getMessage()
            );
        }
    },

    'page.changeTemplate:after' => function (Kirby\Cms\Page $newPage, Kirby\Cms\Page $oldPage) {
        try {
            $helper = new KirbyInternalHelper();

            // Remove stale entry from any index associated with the OLD template
            try {
                $oldManagers = ContentIndexRegistry::getManagersForTemplate($oldPage->intendedTemplate()->name());
                foreach ($oldManagers as $manager) {
                    $manager->removePage($oldPage->id());
                }
            } catch (Throwable $e) {
                KirbyBaseHelper::writeToLogFile('search-index', 'Failed to remove old-template index entry for page ' . $oldPage->id() . ': ' . $e->getMessage());
            }

            // Add/update entry in any index associated with the NEW template
            try {
                $newManagers = ContentIndexRegistry::getManagersForTemplate($newPage->intendedTemplate()->name());
                foreach ($newManagers as $manager) {
                    $manager->indexPage($newPage, $helper);
                }
            } catch (Throwable $e) {
                KirbyBaseHelper::writeToLogFile('search-index', 'Failed to update new-template index entry for page ' . $newPage->id() . ': ' . $e->getMessage());
            }

            // Update file-link (reverse-link) index: a template change to/from
            // file_link changes whether the page contributes a wrapped-file link.
            updateFileLinkIndex($newPage);
        } catch (Throwable $e) {
            KirbyBaseHelper::writeToLogFile('search-index', 'Failed to handle template change for page ' . $newPage->id() . ': ' . $e->getMessage());
        }

        return $newPage;
    },

    'page.move:after' => function (Kirby\Cms\Page $newPage, Kirby\Cms\Page $oldPage) {
        forgetUuidMisses('page.move');
        try {
            $helper = new KirbyInternalHelper();

            $managers = ContentIndexRegistry::getManagersForTemplate($newPage->intendedTemplate()->name());
            foreach ($managers as $manager) {
                // Remove old entry (old page ID) and re-index under the new ID
                $manager->removePage($oldPage->id());
                $manager->indexPage($newPage, $helper);
            }
        } catch (Throwable $e) {
            KirbyBaseHelper::writeToLogFile('search-index', 'Failed to update content index after page move for page ' . $newPage->id() . ': ' . $e->getMessage());
        }

        // Update file-link (reverse-link) index under the new page ID
        updateFileLinkIndex($newPage, $oldPage->id());

        return $newPage;
    },

];