<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Finds the ids of pages using given templates by their content file names
 * (`<template>.txt`, `<template>.<lang>.txt`), without loading pages: a
 * file-name scan of the content folder, where a page-tree walk would load
 * every page on a site of thousands.
 */
final readonly class ContentTemplateScanner
{
    public function __construct(private string $contentRoot)
    {
    }

    /**
     * Returns the ids of pages whose content file is one of the templates, in
     * path order. Draft pages (under `_drafts`) are included.
     *
     * @param list<string> $templates
     * @return list<string>
     */
    public function pageIds(array $templates): array
    {
        if (!is_dir($this->contentRoot)) {
            return [];
        }
        $pattern = '/^(' . implode('|', array_map(static fn(string $t): string => preg_quote($t, '/'), $templates)) . ')(\.[a-z]{2})?\.txt$/';

        $ids = [];
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->contentRoot, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || preg_match($pattern, $file->getFilename()) !== 1) {
                continue;
            }
            $relative = trim(substr($file->getPath(), strlen($this->contentRoot)), '/');
            if ($relative === '' || str_contains('/' . $relative . '/', '/_changes/')) {
                continue;
            }
            $ids[] = self::idFor($relative);
        }
        $ids = array_values(array_unique($ids));
        sort($ids);
        return $ids;
    }

    /**
     * Returns the page id for a content folder path: sorting numbers and the
     * `_drafts` folders dropped.
     */
    public static function idFor(string $relativePath): string
    {
        $slugs = [];
        foreach (explode('/', $relativePath) as $folder) {
            if ($folder === '_drafts') {
                continue;
            }
            $slugs[] = (string) preg_replace('/^\d+_/', '', $folder);
        }
        return implode('/', $slugs);
    }
}
