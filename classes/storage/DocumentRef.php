<?php

declare(strict_types=1);

namespace BSBI\WebBase\storage;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Where a stored document lives: the one place the key layout is defined.
 *
 *   documents/{course}/{unit}/{owner}/{kind}/{timestamp}-{safe name}
 *
 * Course-first, so everything for a course is one prefix; then the unit, the
 * owner (a user id, never a name), the kind of document, and a timestamped
 * filename so two uploads of the same name never collide.
 */
final readonly class DocumentRef
{
    public const string PREFIX = 'documents';

    /**
     * @param string $course A slug identifying the course
     * @param string $unit A slug identifying the unit (or sheet)
     * @param string $owner The owning user's id
     * @param string $kind What the document is (a slug: question-sheet, marked-sheet, form-file, inline-image, …)
     * @param string $filename The original filename; made safe here
     * @param DateTimeImmutable $at When it was stored
     * @throws InvalidArgumentException When any segment is empty or the filename has nothing left once made safe
     */
    public function __construct(
        public string $course,
        public string $unit,
        public string $owner,
        public string $kind,
        public string $filename,
        public DateTimeImmutable $at,
    ) {
        foreach (['course' => $course, 'unit' => $unit, 'owner' => $owner, 'kind' => $kind] as $name => $value) {
            if (self::segment($value) === '') {
                throw new InvalidArgumentException("A document ref needs a non-empty {$name}");
            }
        }
        if (self::safeFilename($filename) === '') {
            throw new InvalidArgumentException('A document ref needs a filename');
        }
    }

    /**
     * The object key.
     */
    public function key(): string
    {
        return implode('/', [
            self::PREFIX,
            self::segment($this->course),
            self::segment($this->unit),
            self::segment($this->owner),
            self::segment($this->kind),
            $this->at->format('Y-m-d-H-i-s') . '-' . self::safeFilename($this->filename),
        ]);
    }

    /**
     * The prefix under which every document of a course lives.
     */
    public static function coursePrefix(string $course): string
    {
        return self::PREFIX . '/' . self::segment($course) . '/';
    }

    /**
     * A path segment: lower-case letters, digits, dots, dashes and underscores only.
     */
    public static function segment(string $value): string
    {
        $value = strtolower(trim($value));
        $value = (string) preg_replace('/[^a-z0-9._-]+/', '-', $value);
        return trim($value, '.-');
    }

    /**
     * A filename that is safe in a key and on a disk: no separators, no
     * traversal, no control characters, and never empty when the original had
     * any usable character.
     */
    public static function safeFilename(string $filename): string
    {
        $filename = basename(str_replace('\\', '/', trim($filename)));
        $filename = (string) preg_replace('/[\x00-\x1F\/\\\\:*?"<>|]+/', '-', $filename);
        $filename = (string) preg_replace('/\s+/', ' ', $filename);
        return trim($filename, ' .-');
    }
}
