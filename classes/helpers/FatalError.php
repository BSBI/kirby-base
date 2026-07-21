<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

/**
 * A PHP fatal error worth reporting, parsed from error_get_last().
 *
 * Fatal errors never reach set_exception_handler, so they are only visible from a
 * shutdown handler. error_get_last() returns a loosely-typed array that also carries
 * warnings and notices, so this class does two jobs: it filters down to genuine fatals,
 * and it turns the untyped array into typed values that are safe to log and report.
 */
final readonly class FatalError
{
    /**
     * Error types that halt execution. Anything outside this list (warnings, notices,
     * deprecations) is normal noise and must not raise an alert.
     */
    public const array FATAL_TYPES = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

    /**
     * @param int $type one of FATAL_TYPES
     * @param string $message the error message
     * @param string $file file the error occurred in
     * @param int $line line the error occurred on
     */
    public function __construct(
        public int $type,
        public string $message,
        public string $file,
        public int $line
    ) {
    }

    /**
     * Builds a FatalError from an error_get_last() result, or null if there is nothing
     * fatal to report.
     *
     * Missing or wrongly-typed entries are tolerated rather than fatal in themselves:
     * this runs during a shutdown that is already going badly.
     *
     * @param array<string, mixed>|null $lastError result of error_get_last()
     * @return self|null the fatal error, or null if absent or not fatal
     */
    public static function fromLastError(array|null $lastError): self|null
    {
        if ($lastError === null) {
            return null;
        }

        $type = $lastError['type'] ?? null;

        if (is_int($type) === false || in_array($type, self::FATAL_TYPES, true) === false) {
            return null;
        }

        $message = $lastError['message'] ?? null;
        $file = $lastError['file'] ?? null;
        $line = $lastError['line'] ?? null;

        return new self(
            $type,
            is_string($message) ? $message : 'unknown error',
            is_string($file) ? $file : 'unknown file',
            is_int($line) ? $line : 0
        );
    }

    /**
     * A stable identifier for this fault, used to throttle repeat alerts so that a
     * persistent fatal alerts periodically rather than once per request.
     *
     * @return string the fingerprint
     */
    public function fingerprint(): string
    {
        return 'fatal|' . $this->type . '|' . $this->message . '|' . $this->file . '|' . $this->line;
    }

    /**
     * A plain-text description for the log and alert body.
     *
     * @param string $pageUrl the URL being requested when the error occurred
     * @return string the description
     */
    public function describe(string $pageUrl = ''): string
    {
        return "Fatal error: " . $this->message . "\n" .
            "File: " . $this->file . "\n" .
            "Line: " . $this->line . "\n" .
            "Page: " . $pageUrl . "\n";
    }
}
