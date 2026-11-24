<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

use FilesystemIterator;
use Kirby\Cms\App;
use Kirby\Cms\File;
use Kirby\Cms\Page;
use Kirby\Cms\Site;
use Kirby\Cms\User;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;
use UnexpectedValueException;

/**
 * Finds the page or file a UUID belongs to by reading content files from disk, one at a
 * time, instead of loading the whole site index (bsbi-web#945).
 *
 * When a UUID is not in Kirby's `uuid` cache, core walks `site()->index(true)`: it builds
 * every page and reads every content file, and on a site of 10,000+ pages one walk can
 * use more than 256 MB. This scanner looks for the `Uuid: <id>` line in the content
 * folder's text files (pages, drafts, and file metadata) and in the accounts folder
 * (users' files), so memory stays flat whatever the size of the site. Once found, only
 * that one page or file is loaded.
 *
 * Folders are read as core's inventory reads them: symlinks followed, folders starting
 * with "." or "_" skipped except `_drafts`, and anything before a folder's first "_"
 * taken as its sort number. So `_changes` (unsaved Panel edits, which repeat the UUID
 * of the page or file they belong to) is skipped.
 */
final readonly class UuidContentScanner
{
    /** How much of each file is read at a time while looking for the `Uuid:` line. */
    private const int CHUNK_BYTES = 65536;

    /**
     * @param App $kirby The app whose content and accounts roots are scanned.
     */
    public function __construct(private App $kirby)
    {
    }

    /**
     * The text files with a `Uuid: <id>` line, in folder order. Usually one; a text field
     * can hold a line that looks the same, so check the model each one gives.
     *
     * @param string $id The UUID without its scheme, e.g. `abc123` for `page://abc123`.
     * @return iterable<string>
     */
    public function candidates(string $id): iterable
    {
        if ($id === '' || preg_match('/[\r\n]/', $id) === 1) {
            return;
        }
        $pattern = '/^Uuid:[ \t]*' . preg_quote($id, '/') . '[ \t]*\r?$/mi';
        $extension = '.' . $this->kirby->contentExtension();

        foreach ([$this->kirby->root('content'), $this->kirby->root('accounts')] as $root) {
            if (!is_string($root) || !is_dir($root)) {
                continue;
            }
            foreach ($this->textFiles($root, $extension) as $path) {
                if ($this->fileContains($path, $pattern)) {
                    yield $path;
                }
            }
        }
    }

    /**
     * The page, or file, whose content file is at `$path`; null when Kirby can't load it.
     *
     * @param string $path A path returned by `candidates()`.
     */
    public function modelFor(string $path): Page|File|null
    {
        $directory = dirname($path);
        $name = $this->withoutLanguage(basename($path, '.' . $this->kirby->contentExtension()));

        try {
            $parent = $this->parentFor($directory);
            if ($parent === null) {
                return null;
            }
            // A text file named after a file beside it is that file's metadata.
            if (is_file($directory . '/' . $name)) {
                $file = $parent->file($name);

                return $file instanceof File ? $file : null;
            }

            return $parent instanceof Page ? $parent : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The content files under `$root`, in the folders core's inventory reads.
     *
     * @return iterable<string>
     */
    private function textFiles(string $root, string $extension): iterable
    {
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator(
                        $root,
                        FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS
                    ),
                    // As core's inventory: folders starting with "." or "_" aren't pages,
                    // except drafts. That leaves out `_changes` too.
                    static fn (SplFileInfo $item): bool => !$item->isDir()
                        || $item->getFilename() === '_drafts'
                        || !preg_match('/^[._]/', $item->getFilename())
                ),
                RecursiveIteratorIterator::LEAVES_ONLY,
                RecursiveIteratorIterator::CATCH_GET_CHILD
            );
            foreach ($iterator as $item) {
                if ($item instanceof SplFileInfo && $item->isFile() && str_ends_with($item->getFilename(), $extension)) {
                    yield $item->getPathname();
                }
            }
        } catch (UnexpectedValueException) {
            // an unreadable root: nothing to find there
        }
    }

    /**
     * True when the file has a line matching `$pattern`. Reads it a chunk at a time, so
     * a large content file never has to be held in memory whole.
     */
    private function fileContains(string $path, string $pattern): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        try {
            $carry = '';
            while (!feof($handle)) {
                $chunk = fread($handle, self::CHUNK_BYTES);
                if ($chunk === false) {
                    return false;
                }
                $text = $carry . $chunk;
                if (preg_match($pattern, $text) === 1) {
                    return true;
                }
                // Keep the last, possibly incomplete, line for the next chunk.
                $lastBreak = strrpos($text, "\n");
                $carry = $lastBreak === false ? $text : substr($text, $lastBreak + 1);
                if (strlen($carry) > self::CHUNK_BYTES) {
                    $carry = substr($carry, -256);
                }
            }

            return false;
        } finally {
            fclose($handle);
        }
    }

    /**
     * The page, site or user a content folder belongs to.
     */
    private function parentFor(string $directory): Page|Site|User|null
    {
        $accounts = $this->kirby->root('accounts');
        if (is_string($accounts) && str_starts_with($directory . '/', rtrim($accounts, '/') . '/')) {
            $userId = basename($directory);
            return $userId === basename($accounts) ? null : $this->kirby->user($userId);
        }

        $content = rtrim((string) $this->kirby->root('content'), '/');
        if ($directory === $content) {
            return $this->kirby->site();
        }
        if (!str_starts_with($directory, $content . '/')) {
            return null;
        }

        $slugs = [];
        foreach (explode('/', substr($directory, strlen($content) + 1)) as $folder) {
            if ($folder === '_drafts') {
                continue;
            }
            // As core's inventory: anything before the first "_" is the sort number.
            $separator = strpos($folder, '_');
            $slugs[] = $separator === false || $separator === 0 ? $folder : substr($folder, $separator + 1);
        }

        return $this->kirby->page(implode('/', $slugs));
    }

    /**
     * `default.en` → `default`, `pic.jpg.cy` → `pic.jpg`: drops a language code suffix.
     */
    private function withoutLanguage(string $name): string
    {
        if (!$this->kirby->multilang()) {
            return $name;
        }
        foreach ($this->kirby->languages()->codes() as $code) {
            if (str_ends_with($name, '.' . $code)) {
                return substr($name, 0, -strlen('.' . $code));
            }
        }

        return $name;
    }
}
