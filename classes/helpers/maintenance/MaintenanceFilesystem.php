<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers\maintenance;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Small, side-effect-focused filesystem primitives shared by the maintenance tasks:
 * measuring a subtree, finding its newest file, and deleting recursively. Kept separate
 * (and static) so both {@see FileAgePruner} and {@see CacheClearTask} reuse one tested
 * implementation instead of each rolling their own recursion.
 *
 * @package BSBI\WebBase\helpers\maintenance
 */
final class MaintenanceFilesystem
{
    /**
     * Total size in bytes of a file or directory subtree (0 if the path is absent).
     *
     * @param string $path absolute path to a file or directory
     * @return int size in bytes
     */
    public static function size(string $path): int
    {
        if (is_file($path)) {
            return (int) filesize($path);
        }

        if (!is_dir($path)) {
            return 0;
        }

        $total = 0;
        foreach (self::walk($path) as $file) {
            if ($file->isFile()) {
                $total += (int) $file->getSize();
            }
        }

        return $total;
    }

    /**
     * The newest *file* modification time anywhere in a subtree.
     *
     * Directory mtimes are ignored on purpose: a container's mtime changes whenever an
     * entry is added or removed, so it reflects the last touch to the folder, not the age
     * of its contents. An empty directory falls back to its own mtime. Returns 0 if the
     * path is absent.
     *
     * @param string $path absolute path to a file or directory
     * @return int unix timestamp
     */
    public static function newestFileMtime(string $path): int
    {
        if (is_file($path)) {
            return (int) filemtime($path);
        }

        if (!is_dir($path)) {
            return 0;
        }

        $newest = 0;
        foreach (self::walk($path) as $file) {
            if ($file->isFile()) {
                $mtime = (int) $file->getMTime();
                if ($mtime > $newest) {
                    $newest = $mtime;
                }
            }
        }

        return $newest > 0 ? $newest : (int) filemtime($path);
    }

    /**
     * Recursively delete a file or directory.
     *
     * @param string $path absolute path to remove
     * @return bool whether removal succeeded
     */
    public static function delete(string $path): bool
    {
        if (is_file($path) || is_link($path)) {
            return @unlink($path);
        }

        if (!is_dir($path)) {
            return false;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            /** @var SplFileInfo $item */
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        return @rmdir($path);
    }

    /**
     * Delete every entry *inside* a directory, keeping the directory itself.
     *
     * @param string $dir absolute directory path
     * @return int number of top-level entries removed
     */
    public static function deleteContents(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }

        $removed = 0;
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (self::delete($dir . '/' . $entry)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Format a byte count as a short human-readable string (B/KB/MB/GB).
     *
     * @param int $bytes the byte count
     * @return string
     */
    public static function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }
        if ($bytes < 1073741824) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        return round($bytes / 1073741824, 2) . ' GB';
    }

    /**
     * Iterate every file/dir under a directory, parents before children.
     *
     * @param string $path absolute directory path
     * @return iterable<SplFileInfo>
     */
    private static function walk(string $path): iterable
    {
        return new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
    }
}
