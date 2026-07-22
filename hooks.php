<?php

use BSBI\WebBase\helpers\ContentIndexRegistry;
use BSBI\WebBase\helpers\FileLinkIndexHelper;
use BSBI\WebBase\helpers\ImageConversionHelper;
use BSBI\WebBase\helpers\KirbyBaseHelper;
use BSBI\WebBase\helpers\KirbyInternalHelper;
use BSBI\WebBase\helpers\SearchIndexHelper;
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

        /** @noinspection PhpUnhandledExceptionInspection */
        $helper = new KirbyInternalHelper();
        /** @noinspection PhpUnhandledExceptionInspection */
        $helper->handleTwoWayTagging($newPage, $oldPage);
        $helper->handleCaches($newPage);

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

return [
    'file.create:after' => function (Kirby\Cms\File $file) {
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

    'page.update:after' => function ($newPage, $oldPage) {
        return handlePageChange($newPage, $oldPage);
    },

    'page.changeTitle:after' => function ($newPage, $oldPage) {
        return handlePageChange($newPage, $oldPage);
    },

    'page.changeSlug:after' => function ($newPage, $oldPage) {
        return handlePageChange($newPage, $oldPage);
    },


    'page.create:after' => function ($page) {
        if ($page->publishedDate()->isEmpty() || $page->publishedBy()->isEmpty()) {
            $user = kirby()->user();
            $page = $page->update([
                'publishedDate' => date('Y-m-d H:i:s'),
                'publishedBy' => $user?->id()
            ]);
        }

        $helper = new KirbyInternalHelper();
        $helper->handleTwoWayTagging($page);
        $helper->handleCaches($page);

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
        $helper->handleCaches($newPage);

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