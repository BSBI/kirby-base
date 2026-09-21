<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\UnhandledException;
use Error;
use Exception;
use Kirby\Exception\NotFoundException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use TypeError;

/**
 * Tests for UnhandledException: deciding whether a throwable that escaped to the global
 * exception handler is a server fault (500, alert the admin) or a routing miss (404, no
 * alert).
 *
 * Kirby's router throws a plain PHP \Exception with code 404 when no route matches — a
 * HEAD request to a Panel URL, for instance. Reporting that as a 500 misreports the site's
 * health and spends the alert channel on noise (bsbi-web#754). The rule is deliberately
 * narrow: the exact class and code the router uses, and nothing else, so a Kirby
 * NotFoundException escaping from application code still alerts.
 */
final class UnhandledExceptionTest extends TestCase
{
    public function testRouterMissIsA404ThatDoesNotNotify(): void
    {
        $unhandled = new UnhandledException(new Exception(
            'No route found for path: "pages/home" and request method: "HEAD"',
            404
        ));

        $this->assertTrue($unhandled->isRoutingMiss());
        $this->assertSame(404, $unhandled->httpStatus());
        $this->assertFalse($unhandled->shouldNotify());
    }

    /**
     * @return array<string, array{Throwable}>
     */
    public static function serverFaultProvider(): array
    {
        return [
            'plain exception, no code' => [new Exception('boom')],
            'plain exception, code 500' => [new Exception('boom', 500)],
            'subclass with code 404' => [new RuntimeException('not here', 404)],
            'Kirby NotFoundException (HTTP 404)' => [new NotFoundException(message: 'The file could not be found')],
            'Error' => [new Error('fatal-ish')],
            'TypeError' => [new TypeError('wrong type')],
        ];
    }

    /**
     * Anything other than the router's exact shape is a fault we want to hear about — a
     * subclass carrying 404, or a Kirby NotFoundException from a controller, both point
     * at application code or content, not at a request for a URL that has no route.
     */
    #[DataProvider('serverFaultProvider')]
    public function testEverythingElseIsA500ThatNotifies(Throwable $throwable): void
    {
        $unhandled = new UnhandledException($throwable);

        $this->assertFalse($unhandled->isRoutingMiss());
        $this->assertSame(500, $unhandled->httpStatus());
        $this->assertTrue($unhandled->shouldNotify());
    }

    /**
     * The fingerprint keys the alert throttle's marker files. It must stay byte-identical
     * to the string the handler built inline before this class existed, so a fault that
     * was throttled before the deploy stays throttled after it.
     */
    public function testFingerprintIsMessageFileAndLine(): void
    {
        $throwable = new Exception('boom');
        $unhandled = new UnhandledException($throwable);

        $this->assertSame(
            'boom|' . $throwable->getFile() . '|' . $throwable->getLine(),
            $unhandled->fingerprint()
        );
    }

    public function testDescribeCarriesMessageFileLineTraceAndPage(): void
    {
        $throwable = new Exception('boom');
        $text = (new UnhandledException($throwable))->describe('/panel/pages/home');

        $this->assertStringContainsString("Message: boom\n", $text);
        $this->assertStringContainsString('File: ' . $throwable->getFile() . "\n", $text);
        $this->assertStringContainsString('Line: ' . $throwable->getLine() . "\n", $text);
        $this->assertStringContainsString('Trace: #0', $text);
        $this->assertStringContainsString("Page: /panel/pages/home\n", $text);
    }

    public function testDescribeHtmlEscapesTheMessageAndPage(): void
    {
        $html = (new UnhandledException(new Exception('<script>alert(1)</script>')))
            ->describeHtml('/?q=<b>');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('/?q=&lt;b&gt;', $html);
        $this->assertStringContainsString('<b>An unhandled exception occurred:</b>', $html);
    }
}
