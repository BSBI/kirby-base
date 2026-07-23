<?php

declare(strict_types=1);

namespace BSBI\WebBase\models;

/**
 * Class GlossaryMatch
 * A single occurrence of a glossary term found within block content,
 * with enough context to present the match to an editor for review.
 *
 * @package BSBI\WebBase
 */
final readonly class GlossaryMatch
{
    /**
     * Constructor.
     *
     * @param string $term The glossary term that was matched
     * @param string $matchedText The matched text as it appears in the content (original casing)
     * @param string $blockId The UUID of the block the match was found in
     * @param int $offset Byte offset of the match within the block HTML
     * @param string $contextBefore Plain text immediately preceding the match
     * @param string $contextAfter Plain text immediately following the match
     */
    public function __construct(
        private string $term,
        private string $matchedText,
        private string $blockId,
        private int    $offset,
        private string $contextBefore,
        private string $contextAfter,
    ) {
    }

    /**
     * Get the glossary term that was matched
     * @return string
     */
    public function getTerm(): string
    {
        return $this->term;
    }

    /**
     * Get the matched text as it appears in the content
     * @return string
     */
    public function getMatchedText(): string
    {
        return $this->matchedText;
    }

    /**
     * Get the UUID of the block the match was found in
     * @return string
     */
    public function getBlockId(): string
    {
        return $this->blockId;
    }

    /**
     * Get the byte offset of the match within the block HTML
     * @return int
     */
    public function getOffset(): int
    {
        return $this->offset;
    }

    /**
     * Get the plain text immediately preceding the match
     * @return string
     */
    public function getContextBefore(): string
    {
        return $this->contextBefore;
    }

    /**
     * Get the plain text immediately following the match
     * @return string
     */
    public function getContextAfter(): string
    {
        return $this->contextAfter;
    }
}
