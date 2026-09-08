<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers\maintenance;

use BSBI\WebBase\helpers\KirbyBaseHelper;
use FilesystemIterator;
use Kirby\Cms\App;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Lists `file://` and `page://` references that point at nothing (bsbi-web#732).
 *
 * Works on the content text files directly, not on Kirby models: it collects
 * every `Uuid:` line as the set of UUIDs that exist, and every `file://` /
 * `page://` token as a reference, then reports the references with no match,
 * grouped by page. Independent of the uuid cache, so it is right whether or not
 * the cache has been populated.
 *
 * Preview counts and samples; run writes the full list to
 * `logs/dangling-references.log` (overwriting) so editors can fix the pages.
 * Nothing is deleted.
 */
final readonly class DanglingReferenceAuditTask implements MaintenanceTask, DeferredPreviewTask
{
    public const string LOG_FILE = 'dangling-references';

    private const int SAMPLE_LINES = 10;

    public function __construct(private App $kirby)
    {
    }

    public function key(): string
    {
        return 'dangling-references';
    }

    public function label(): string
    {
        return 'Dangling references';
    }

    public function description(): string
    {
        return 'List file:// and page:// references whose target no longer exists. Each one costs a '
            . 'full site walk when rendered. Writes the list to logs/' . self::LOG_FILE . '.log; nothing is deleted.';
    }

    public function preview(MaintenanceOptions $options): MaintenancePreview
    {
        $dangling = $this->dangling();
        $count = array_sum(array_map('count', $dangling));

        return new MaintenancePreview($count, 0, array_slice($this->lines($dangling), 0, self::SAMPLE_LINES));
    }

    public function run(MaintenanceOptions $options, int $offset = 0, int $limit = 0): MaintenanceRunResult
    {
        $dangling = $this->dangling();
        $count = array_sum(array_map('count', $dangling));

        $lines = $this->lines($dangling);
        KirbyBaseHelper::writeToLogFile(
            self::LOG_FILE,
            $count === 0
                ? 'No dangling references found.'
                : $count . ' dangling reference(s) on ' . count($dangling) . ' page(s):' . PHP_EOL . implode(PHP_EOL, $lines),
            true
        );

        return new MaintenanceRunResult(true, $count, 0);
    }

    /**
     * Dangling references grouped by page id.
     *
     * @return array<string, list<string>> page id => references such as `file://abc…`
     */
    public function dangling(): array
    {
        $existing = [];
        $references = [];

        foreach ($this->contentTextFiles() as $file) {
            $text = (string) file_get_contents($file->getPathname());

            if (preg_match_all('/^Uuid:\s*([A-Za-z0-9-]+)\s*$/m', $text, $found)) {
                foreach ($found[1] as $uuid) {
                    $existing[$uuid] = true;
                }
            }

            if (preg_match_all('#\b(file|page)://([A-Za-z0-9-]+)#', $text, $found, PREG_SET_ORDER)) {
                $pageId = $this->pageIdOf($file);
                foreach ($found as $match) {
                    $references[$pageId][$match[1] . '://' . $match[2]] = $match[2];
                }
            }
        }

        $dangling = [];
        foreach ($references as $pageId => $refs) {
            foreach ($refs as $reference => $uuid) {
                if (!isset($existing[$uuid])) {
                    $dangling[$pageId][] = $reference;
                }
            }
        }
        ksort($dangling);

        return $dangling;
    }

    /**
     * @param array<string, list<string>> $dangling
     * @return list<string>
     */
    private function lines(array $dangling): array
    {
        $lines = [];
        foreach ($dangling as $pageId => $refs) {
            $lines[] = $pageId . ': ' . implode(', ', $refs);
        }

        return $lines;
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private function contentTextFiles(): iterable
    {
        $root = $this->kirby->root('content');
        if ($root === null || !is_dir($root)) {
            return;
        }

        // Symlinks are not followed: the audit stays inside the content root.
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS)
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isLink()) {
                continue;
            }
            if ($file->isFile() && strtolower($file->getExtension()) === 'txt') {
                yield $file;
            }
        }
    }

    /**
     * The Kirby page id for a content text file: its directory relative to the
     * content root, minus sorting prefixes and `_drafts`/`_changes` segments.
     */
    private function pageIdOf(SplFileInfo $file): string
    {
        $root = rtrim((string) $this->kirby->root('content'), '/');
        $dir = trim(substr($file->getPath(), strlen($root)), '/');
        if ($dir === '') {
            return 'site';
        }

        $segments = [];
        foreach (explode('/', $dir) as $segment) {
            // `_drafts` holds draft pages, `_changes` a page's unsaved Panel version: both are the page itself
            if ($segment === '_drafts' || $segment === '_changes') {
                continue;
            }
            $segments[] = (string) preg_replace('/^\d+_/', '', $segment);
        }

        return implode('/', $segments);
    }
}
