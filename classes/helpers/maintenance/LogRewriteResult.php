<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers\maintenance;

/**
 * The outcome of applying log retention to one file's raw content.
 *
 * Pure data: {@see LogRetentionPolicy} produces this from a string without touching the
 * filesystem, so the decision logic is unit-testable with fixture content.
 *
 * @package BSBI\WebBase\helpers\maintenance
 */
final readonly class LogRewriteResult
{
    public const string MODE_EMPTY = 'empty';
    public const string MODE_UNCHANGED = 'unchanged';
    public const string MODE_DATE = 'date';
    public const string MODE_SIZE_CAP = 'sizecap';

    /**
     * @param string $content the rewritten content that should replace the file
     * @param string $mode which rule produced the result (one of the MODE_* constants)
     * @param int $originalBytes byte length of the input
     * @param int $newBytes byte length of {@see $content}
     * @param int $originalLineCount line count of the input
     * @param int $newLineCount line count of {@see $content}
     */
    public function __construct(
        public string $content,
        public string $mode,
        public int $originalBytes,
        public int $newBytes,
        public int $originalLineCount,
        public int $newLineCount,
    ) {
    }

    /**
     * Bytes that would be reclaimed by writing {@see $content} back.
     *
     * @return int
     */
    public function reclaimedBytes(): int
    {
        return max(0, $this->originalBytes - $this->newBytes);
    }

    /**
     * Whether the rewrite actually changes the file.
     *
     * @return bool
     */
    public function changed(): bool
    {
        return $this->newBytes !== $this->originalBytes;
    }
}
