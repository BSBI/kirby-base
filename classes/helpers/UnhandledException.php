<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

use Exception;
use Throwable;

/**
 * A throwable that escaped to the global exception handler, classified for reporting.
 *
 * Almost everything that reaches the handler is a server fault: answer 500, alert the
 * admin. The exception is a routing miss. Kirby's router throws a plain PHP \Exception with
 * code 404 when no route matches the path and method — most often a HEAD request to a
 * Panel URL from a link scanner, since the Panel registers no HEAD routes — and nothing
 * upstream catches it. Reporting that as a 500 misreports the site's health to whatever is
 * probing it and spends the alert channel on noise (bsbi-web#754).
 *
 * The routing-miss rule is deliberately narrow: the exact class and code the router uses.
 * A subclass carrying 404, or a Kirby NotFoundException escaping from application code,
 * points at code or content and keeps alerting.
 */
final readonly class UnhandledException
{
    /**
     * @param Throwable $throwable the throwable that reached the handler
     */
    public function __construct(public Throwable $throwable)
    {
    }

    /**
     * Whether this is Kirby's router reporting that no route matched.
     *
     * @return bool true for the router's exact shape: a plain \Exception with code 404
     */
    public function isRoutingMiss(): bool
    {
        return $this->throwable::class === Exception::class && $this->throwable->getCode() === 404;
    }

    /**
     * The HTTP status the response should carry.
     *
     * @return int 404 for a routing miss, 500 otherwise
     */
    public function httpStatus(): int
    {
        return $this->isRoutingMiss() ? 404 : 500;
    }

    /**
     * Whether the admin should be alerted.
     *
     * @return bool false for a routing miss, true otherwise
     */
    public function shouldNotify(): bool
    {
        return $this->isRoutingMiss() === false;
    }

    /**
     * A stable identifier for this fault, used to throttle repeat alerts.
     *
     * Kept byte-identical to the string the handler used to build inline, so throttle
     * marker files written before this class existed keep suppressing repeats.
     *
     * @return string the fingerprint
     */
    public function fingerprint(): string
    {
        return $this->throwable->getMessage() . '|' . $this->throwable->getFile() . '|' . $this->throwable->getLine();
    }

    /**
     * A plain-text description for the log and the admin-only error page.
     *
     * @param string $pageUrl the URL being requested when the exception occurred
     * @return string the description
     */
    public function describe(string $pageUrl = ''): string
    {
        return 'Message: ' . $this->throwable->getMessage() . "\n" .
            'File: ' . $this->throwable->getFile() . "\n" .
            'Line: ' . $this->throwable->getLine() . "\n" .
            'Trace: ' . $this->throwable->getTraceAsString() . "\n" .
            'Page: ' . $pageUrl . "\n";
    }

    /**
     * The HTML body for the admin alert email, with every value escaped.
     *
     * @param string $pageUrl the URL being requested when the exception occurred
     * @return string the HTML
     */
    public function describeHtml(string $pageUrl = ''): string
    {
        return '<b>An unhandled exception occurred:</b><br>' .
            '<b>Message</b>: ' . htmlspecialchars($this->throwable->getMessage()) . '<br>' .
            '<b>File:</b> ' . htmlspecialchars($this->throwable->getFile()) . '<br>' .
            '<b>Line:</b> ' . $this->throwable->getLine() . '<br>' .
            '<b>Trace:</b> ' . htmlspecialchars($this->throwable->getTraceAsString()) . '<br>' .
            '<b>Page:</b> ' . htmlspecialchars($pageUrl);
    }
}
