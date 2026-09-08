<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

use Kirby\Cache\Cache;
use Kirby\Cms\App;
use Kirby\Cms\File;
use Kirby\Cms\Files;
use Kirby\Cms\Page;
use Kirby\Cms\Pages;
use Kirby\Content\Field;
use Kirby\Uuid\Uuid;
use Throwable;

/**
 * Resolves `file://` and `page://` references without paying for dangling ones
 * on every request (bsbi-web#732).
 *
 * Kirby answers a UUID from its `uuid` cache and, on a cache miss, walks the
 * entire site index; a hit is cached, a miss never is. So one reference to a
 * file that no longer exists (or has not yet been copied into a local content
 * tree) costs a full walk of 10,000+ pages on every request that renders it,
 * with nothing in the logs.
 *
 * This resolver keeps a short-lived list of misses in the site cache
 * (`option('cacheName')`, one entry; TTL one hour, or `uuidResolver.missTtlSeconds`).
 * A UUID already in Kirby's cache resolves exactly as core does; a recorded miss
 * returns null without touching the index; anything else goes through core once
 * and, if that finds nothing, is recorded and logged once per TTL window to
 * `logs/uuid-misses.log`.
 *
 * Plain ids and paths (`home/pic.svg`, `about/team`) are passed straight to
 * core; they are cheap and never recorded.
 */
final class UuidResolver
{
    /** How long a miss is remembered when the `uuidResolver.missTtlSeconds` option is unset. */
    public const int DEFAULT_MISS_TTL_SECONDS = 3600;

    /** Config option overriding the miss TTL, in seconds (a positive integer). */
    public const string MISS_TTL_OPTION = 'uuidResolver.missTtlSeconds';

    /** The single cache key holding the miss list (`uuid => expiresAt`). */
    public const string MISS_CACHE_KEY = 'uuid-misses';

    public const string LOG_FILE = 'uuid-misses';

    private static ?self $instance = null;

    private ?Cache $cache = null;

    /**
     * @param App $kirby The app whose uuid cache, site cache and content are used.
     */
    public function __construct(private readonly App $kirby)
    {
    }

    /**
     * A shared instance for the current app, for snippets and helpers.
     */
    public static function instance(): self
    {
        $kirby = App::instance();
        if (self::$instance === null || self::$instance->kirby !== $kirby) {
            self::$instance = new self($kirby);
        }

        return self::$instance;
    }

    /**
     * Resolves a `file://` UUID, or a plain file id/path, to a File.
     *
     * @param string $value A `file://` UUID or a file id such as `home/pic.svg`.
     * @param Page|null $parent Preferred parent: its own files are checked first, as core does.
     */
    public function file(string $value, ?Page $parent = null): ?File
    {
        if (!Uuid::is($value, 'file')) {
            $model = $this->core(fn () => $this->kirby->file($value, $parent));

            return $model instanceof File ? $model : null;
        }

        // A parent's own files are a cheap, exact place to look before anything else.
        if ($parent !== null) {
            $id = (string) parse_url($value, PHP_URL_HOST);
            foreach ($parent->files() as $file) {
                $uuidField = $file->content()->get('uuid');
                if ($uuidField instanceof Field && $uuidField->value() === $id) {
                    return $file;
                }
            }
        }

        $model = $this->resolveUuid($value, fn () => $this->kirby->file($value));

        return $model instanceof File ? $model : null;
    }

    /**
     * Resolves a `page://` UUID, or a plain page id, to a Page.
     *
     * @param string $value A `page://` UUID or a page id such as `about/team`.
     * @return Page|null The page, or null when it does not exist (or the reference is a known miss).
     */
    public function page(string $value): ?Page
    {
        if (!Uuid::is($value, 'page')) {
            $model = $this->core(fn () => $this->kirby->page($value));

            return $model instanceof Page ? $model : null;
        }

        $model = $this->resolveUuid($value, fn () => $this->kirby->page($value));

        return $model instanceof Page ? $model : null;
    }

    /**
     * Resolves every value in a list, skipping the dangling ones.
     *
     * @param iterable<mixed> $values `file://` UUIDs or file ids; non-strings are ignored.
     * @param Page|null $parent Preferred parent for the lookups.
     * @return Files The files found, in the list's order.
     */
    public function files(iterable $values, ?Page $parent = null): Files
    {
        $files = new Files([]);
        foreach ($values as $value) {
            if (is_string($value) && ($file = $this->file($value, $parent)) !== null) {
                $files->add($file);
            }
        }

        return $files;
    }

    /**
     * Resolves every value in a list to pages, skipping the dangling ones.
     *
     * @param iterable<mixed> $values `page://` UUIDs or page ids; non-strings are ignored.
     * @return Pages The pages found, in the list's order.
     */
    public function pages(iterable $values): Pages
    {
        $pages = new Pages([]);
        foreach ($values as $value) {
            if (is_string($value) && ($page = $this->page($value)) !== null) {
                $pages->add($page);
            }
        }

        return $pages;
    }

    /**
     * The first resolvable file in a files/image field (the yaml list Kirby stores).
     *
     * @param Field|null $field A files field; null yields null.
     * @param Page|null $parent Preferred parent for the lookup; defaults to the field's page.
     * @return File|null The first file found, or null when none resolves.
     */
    public function fileFromField(?Field $field, ?Page $parent = null): ?File
    {
        return $field === null ? null : $this->filesFromField($field, $parent)->first();
    }

    /**
     * Every resolvable file in a files/image field, dangling references skipped.
     *
     * @param Field|null $field A files field (yaml list of `file://` UUIDs or ids); null yields no files.
     * @param Page|null $parent Preferred parent for the lookup; defaults to the field's page.
     * @return Files The files found, in field order.
     */
    public function filesFromField(?Field $field, ?Page $parent = null): Files
    {
        if ($field === null || $field->isEmpty()) {
            return new Files([]);
        }

        return $this->files($field->toData('yaml'), $parent ?? self::pageOf($field));
    }

    /**
     * The first resolvable page in a pages field.
     *
     * @param Field|null $field A pages field (yaml list of `page://` UUIDs or ids); null yields null.
     * @return Page|null The first page found, or null when none resolves.
     */
    public function pageFromField(?Field $field): ?Page
    {
        return $field === null ? null : $this->pagesFromField($field)->first();
    }

    /**
     * Every resolvable page in a pages field, dangling references skipped.
     *
     * @param Field|null $field A pages field (yaml list of `page://` UUIDs or ids); null yields no pages.
     * @return Pages The pages found, in field order.
     */
    public function pagesFromField(?Field $field): Pages
    {
        if ($field === null || $field->isEmpty()) {
            return new Pages([]);
        }

        return $this->pages($field->toData('yaml'));
    }

    /**
     * True while the UUID is on the miss list and its entry has not expired.
     *
     * @param string $uuid The full reference, e.g. `file://abc123`.
     * @return bool
     */
    public function isKnownMiss(string $uuid): bool
    {
        $misses = $this->misses();

        return isset($misses[$uuid]) && $misses[$uuid] > time();
    }

    /**
     * Records a miss. Public so tests and audits can seed the list; normal
     * callers never need it.
     *
     * The list is one cache entry updated read-modify-write without a lock: two
     * requests recording different misses at the same instant can lose one, which
     * only costs that UUID one more walk before it is recorded again. Misses are
     * rare by design, so this is accepted over a per-UUID key scheme.
     *
     * @param string $uuid The full reference, e.g. `file://abc123`.
     * @param int|null $ttlSeconds How long to remember it; null uses the configured TTL, a negative value records an already-expired entry (tests).
     */
    public function recordMiss(string $uuid, ?int $ttlSeconds = null): void
    {
        $misses = $this->misses();
        $misses[$uuid] = time() + ($ttlSeconds ?? $this->missTtlSeconds());
        $this->saveMisses($misses);
    }

    /**
     * How long a miss is remembered, in seconds: the `uuidResolver.missTtlSeconds`
     * option when it is a positive integer, otherwise one hour. The hooks clear the
     * list on every Panel create/rename/move/duplicate, so the TTL only bounds how
     * long a file added by filesystem copy can stay hidden.
     */
    public function missTtlSeconds(): int
    {
        $configured = $this->kirby->option(self::MISS_TTL_OPTION);

        return is_int($configured) && $configured > 0 ? $configured : self::DEFAULT_MISS_TTL_SECONDS;
    }

    /**
     * Drops the whole miss list, e.g. after a file or page is created or renamed.
     */
    public function forgetMisses(): void
    {
        try {
            $this->cache()->remove(self::MISS_CACHE_KEY);
        } catch (Throwable) {
            // a cache that cannot be written cannot hold misses either
        }
    }

    /**
     * Cache hit → core; known miss → null; otherwise core once, recording a miss.
     *
     * @param callable(): (File|Page|null) $lookup The core lookup, which may walk the index.
     */
    private function resolveUuid(string $uuid, callable $lookup): File|Page|null
    {
        $kirbyUuid = Uuid::for($uuid);
        if ($kirbyUuid === null) {
            // UUIDs disabled site-wide: nothing to cache, behave as core
            return $this->core($lookup);
        }

        if ($kirbyUuid->isCached()) {
            $model = $this->core($lookup);
            if ($model !== null && $this->isKnownMiss($uuid)) {
                $this->forgetMiss($uuid);
            }

            return $model;
        }

        if ($this->isKnownMiss($uuid)) {
            return null;
        }

        $model = $this->core($lookup);
        if ($model === null) {
            $this->recordMiss($uuid);
            KirbyBaseHelper::writeToLogFile(
                self::LOG_FILE,
                'No model for ' . $uuid . ' — recorded as a miss for ' . $this->missTtlSeconds() . 's'
            );
        }

        return $model;
    }

    /**
     * Runs a core lookup, turning any exception into null.
     *
     * Deliberately broad: with `content.uuid.index` off Kirby throws NotFoundException,
     * and a permission error on a protected model must resolve to "no model" here just as
     * it does elsewhere; the miss is then recorded and logged like any other, so a
     * permission problem shows in `uuid-misses.log` rather than as a page error.
     *
     * @param callable(): (File|Page|null) $lookup
     * @return File|Page|null
     */
    private function core(callable $lookup): File|Page|null
    {
        try {
            return $lookup();
        } catch (Throwable) {
            return null;
        }
    }

    private function forgetMiss(string $uuid): void
    {
        $misses = $this->misses();
        unset($misses[$uuid]);
        $this->saveMisses($misses);
    }

    /**
     * The miss list with expired entries pruned.
     *
     * @return array<string, int>
     */
    private function misses(): array
    {
        try {
            $raw = $this->cache()->get(self::MISS_CACHE_KEY);
        } catch (Throwable) {
            return [];
        }
        if (!is_array($raw)) {
            return [];
        }

        $now = time();
        $live = [];
        foreach ($raw as $uuid => $expiresAt) {
            if (is_string($uuid) && is_int($expiresAt) && $expiresAt > $now) {
                $live[$uuid] = $expiresAt;
            }
        }

        return $live;
    }

    /**
     * @param array<string, int> $misses
     */
    private function saveMisses(array $misses): void
    {
        try {
            if ($misses === []) {
                $this->cache()->remove(self::MISS_CACHE_KEY);
            } else {
                // cache-level expiry (minutes) is a backstop; entries carry their own expiry
                $this->cache()->set(self::MISS_CACHE_KEY, $misses, (int) ceil($this->missTtlSeconds() / 60) + 1);
            }
        } catch (Throwable) {
            // an unwritable cache degrades to core behaviour
        }
    }

    /**
     * The site cache named by `cacheName` (a NullCache when unconfigured).
     */
    private function cache(): Cache
    {
        $name = $this->kirby->option('cacheName', 'bsbi');

        return $this->cache ??= $this->kirby->cache(is_string($name) && $name !== '' ? $name : 'bsbi');
    }

    private static function pageOf(Field $field): ?Page
    {
        $parent = $field->parent();

        return $parent instanceof Page ? $parent : null;
    }
}
