<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

use Closure;
use Kirby\Cms\App;
use Kirby\Cms\File;
use Kirby\Cms\Page;
use Kirby\Content\Field;
use Kirby\Exception\InvalidArgumentException;
use Kirby\Http\Response;

/**
 * Permanent URLs for File Archive files (bsbi-web#570).
 *
 * A file in the archive page carries a `permanentUrl` slug and is addressed as
 * `/files/<slug>` everywhere: `$file->url()` reports that URL (via the `file::url`
 * component registered in index.php), so writer links, file blocks, feeds and the
 * Panel all emit it; and the route streams the file at that URL instead of
 * redirecting to the hashed media URL — which is what used to end up in the address
 * bar and in the links people copied.
 *
 * Every archive file gets a slug on upload (its filename) and slugs are validated on
 * save, so the URL people see is stable across file replacements.
 *
 * Scope is the archive page only (option `fileArchive.pageId`, default
 * `file-archive`): a `permanentUrl` field on any other file is ignored, and sites
 * without an archive page are unaffected.
 *
 * @package BSBI\WebBase\helpers
 */
final readonly class FileArchiveService
{
    public const OPTION_PAGE_ID = 'fileArchive.pageId';
    public const DEFAULT_PAGE_ID = 'file-archive';
    public const URL_PREFIX = 'files';
    public const FIELD = 'permanentUrl';
    public const CACHE_MAX_AGE = 3600;

    /** Characters a slug may contain: what Kirby's own safe filenames use, plus case. */
    private const SLUG_PATTERN = '/^[A-Za-z0-9@._-]+$/';

    /**
     * @param string $archivePageId ID of the page that holds the archive files.
     */
    public function __construct(
        private App $kirby,
        private string $archivePageId = self::DEFAULT_PAGE_ID,
    ) {
    }

    /**
     * Builds a service from the App's `fileArchive.pageId` option.
     */
    public static function fromKirby(App $kirby): self
    {
        $pageId = $kirby->option(self::OPTION_PAGE_ID, self::DEFAULT_PAGE_ID);
        return new self($kirby, is_string($pageId) && $pageId !== '' ? $pageId : self::DEFAULT_PAGE_ID);
    }

    /**
     * ID of the page whose files carry permanent URLs.
     */
    public function archivePageId(): string
    {
        return $this->archivePageId;
    }

    /**
     * The archive page, or null when the site has none.
     */
    public function archivePage(): ?Page
    {
        return $this->kirby->page($this->archivePageId);
    }

    /**
     * Whether the file sits directly under the archive page.
     *
     * Cheap on purpose: `$file->url()` runs for every image on every page, so this is
     * a parent-id comparison and nothing more.
     */
    public function isArchiveFile(File $file): bool
    {
        $parent = $file->parent();
        return $parent instanceof Page && $parent->id() === $this->archivePageId;
    }

    /**
     * The file's permanent-URL slug, or '' when it has none.
     */
    public function slugOf(File $file): string
    {
        $field = $file->content()->get(self::FIELD);
        $value = $field instanceof Field ? $field->value() : null;
        return is_string($value) ? trim($value) : '';
    }

    /**
     * The absolute permanent URL, or null when the file is not an archive file
     * with a slug.
     */
    public function permanentUrl(File $file): ?string
    {
        if (!$this->isArchiveFile($file)) {
            return null;
        }
        $slug = $this->slugOf($file);
        if ($slug === '') {
            return null;
        }
        return $this->kirby->url() . '/' . self::URL_PREFIX . '/' . $slug;
    }

    /**
     * The `file::url` component body: the permanent URL for archive files with a
     * slug, otherwise whatever Kirby would have produced.
     *
     * @param Closure|null $native Kirby's native `file::url` component, when available.
     */
    public function resolveUrl(File $file, ?Closure $native): string
    {
        $permanent = $this->permanentUrl($file);
        if ($permanent !== null) {
            return $permanent;
        }
        if ($native !== null) {
            $url = $native($this->kirby, $file);
            if (is_string($url)) {
                return $url;
            }
        }
        return $file->mediaUrl();
    }

    /**
     * Finds the archive file for a slug. Exact match, including case.
     *
     * A linear scan of the archive page's files, once per /files/ request — fine for
     * an archive of hundreds; index it if the archive ever reaches many thousands.
     */
    public function findBySlug(string $slug): ?File
    {
        if ($slug === '') {
            return null;
        }
        $archive = $this->archivePage();
        if ($archive === null) {
            return null;
        }
        foreach ($archive->files() as $file) {
            if ($this->slugOf($file) === $slug) {
                return $file;
            }
        }
        return null;
    }

    /**
     * Streams the file for a slug, or null when no archive file has it.
     *
     * @param string|null $ifModifiedSince The request's If-Modified-Since header, if any.
     */
    public function respond(string $slug, ?string $ifModifiedSince = null): ?Response
    {
        $file = $this->findBySlug($slug);
        if ($file === null || !file_exists($file->root() ?? '')) {
            return null;
        }
        return $this->response($file, $ifModifiedSince);
    }

    /**
     * The streamed response for an archive file: inline (opens in the browser, saves
     * under its real filename), short public caching so a replaced file propagates,
     * byte-range support via Kirby's file response, and 304 when the client already
     * has the current version.
     *
     * @param string|null $ifModifiedSince The request's If-Modified-Since header, if any.
     */
    public function response(File $file, ?string $ifModifiedSince = null): Response
    {
        $root         = (string) $file->root();
        $modified     = filemtime($root) ?: time();
        $lastModified = gmdate('D, d M Y H:i:s', $modified) . ' GMT';
        $headers      = [
            'Content-Disposition' => 'inline; filename="' . self::headerSafeFilename($file->filename()) . '"',
            'Last-Modified'       => $lastModified,
            'Cache-Control'       => 'public, max-age=' . self::CACHE_MAX_AGE,
        ];

        if ($ifModifiedSince !== null) {
            $since = strtotime($ifModifiedSince);
            if ($since !== false && $since >= $modified) {
                return new Response('', null, 304, $headers);
            }
        }

        return Response::file($root, ['headers' => $headers]);
    }

    /**
     * A filename safe to quote in a Content-Disposition header. Kirby's safe names
     * already contain nothing but letters, digits, `-`, `_`, `.` and `@`; this guards
     * a content file edited by hand, where a quote, backslash or control character
     * would otherwise break the header.
     */
    public static function headerSafeFilename(string $filename): string
    {
        $safe = preg_replace('/["\\\\\x00-\x1F\x7F]/', '', $filename) ?? '';
        return $safe !== '' ? $safe : 'file';
    }

    /**
     * The slug a newly uploaded archive file gets when the editor has not set one:
     * its filename, extension included, so the URL says what it is and the browser
     * saves it under the right name.
     */
    public function defaultSlug(File $file): string
    {
        return $file->filename();
    }

    /**
     * Validates a slug about to be saved on an archive file.
     *
     * An empty slug is allowed (the file falls back to its media URL). Otherwise the
     * slug must be URL-safe and not in use by another archive file — the route
     * matches exactly, so two files with one slug would make the second unreachable.
     *
     * @param File $file The file being saved, so it may keep its own slug.
     * @throws InvalidArgumentException With the message the editor sees in the Panel.
     */
    public function validateSlug(string $slug, File $file): void
    {
        $slug = trim($slug);
        if ($slug === '') {
            return;
        }
        if (preg_match('/\s/', $slug) === 1) {
            throw new InvalidArgumentException(
                'The permanent URL must not contain spaces — use hyphens instead.'
            );
        }
        if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            throw new InvalidArgumentException(
                'The permanent URL may only contain letters, numbers, hyphens, underscores and dots.'
            );
        }
        $existing = $this->findBySlug($slug);
        if ($existing !== null && $existing->id() !== $file->id()) {
            throw new InvalidArgumentException(
                'The permanent URL "' . $slug . '" is already used by ' . $existing->filename() . '.'
            );
        }
    }

    /**
     * Reads the permanent-URL slug from a hook's update values, whichever case the
     * key arrived in (Panel form data keeps the blueprint's camelCase; stored
     * content is lowercase). Null when the update does not touch the field.
     *
     * @param array<string, mixed> $values
     */
    public static function slugFromValues(array $values): ?string
    {
        foreach ($values as $key => $value) {
            if (strtolower((string) $key) === strtolower(self::FIELD)) {
                return is_string($value) ? trim($value) : '';
            }
        }
        return null;
    }
}
