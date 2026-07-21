<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\FatalError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for FatalError: deciding which entries from error_get_last() are fatals worth
 * reporting from a shutdown handler.
 *
 * Getting the type list wrong fails in both directions — too broad and every notice
 * triggers an alert, too narrow and real fatals pass silently — so the filter is pinned
 * here rather than left inline in the plugin bootstrap.
 */
final class FatalErrorTest extends TestCase
{
    public function testReturnsNullWhenThereIsNoLastError(): void
    {
        $this->assertNull(FatalError::fromLastError(null));
    }

    /**
     * @return array<string, array{int}>
     */
    public static function fatalTypeProvider(): array
    {
        return [
            'E_ERROR' => [E_ERROR],
            'E_PARSE' => [E_PARSE],
            'E_CORE_ERROR' => [E_CORE_ERROR],
            'E_COMPILE_ERROR' => [E_COMPILE_ERROR],
            'E_USER_ERROR' => [E_USER_ERROR],
        ];
    }

    #[DataProvider('fatalTypeProvider')]
    public function testRecognisesFatalTypes(int $type): void
    {
        $error = FatalError::fromLastError([
            'type' => $type,
            'message' => 'Allowed memory size exhausted',
            'file' => '/app/index.php',
            'line' => 42,
        ]);

        $this->assertInstanceOf(FatalError::class, $error);
        $this->assertSame($type, $error->type);
        $this->assertSame('Allowed memory size exhausted', $error->message);
        $this->assertSame('/app/index.php', $error->file);
        $this->assertSame(42, $error->line);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function nonFatalTypeProvider(): array
    {
        return [
            'E_WARNING' => [E_WARNING],
            'E_NOTICE' => [E_NOTICE],
            'E_DEPRECATED' => [E_DEPRECATED],
            'E_USER_WARNING' => [E_USER_WARNING],
            'E_USER_NOTICE' => [E_USER_NOTICE],
            'E_USER_DEPRECATED' => [E_USER_DEPRECATED],
        ];
    }

    /**
     * A request that merely emitted a warning must not be reported as a fatal, or every
     * deprecation notice on the site becomes an alert email.
     */
    #[DataProvider('nonFatalTypeProvider')]
    public function testIgnoresNonFatalTypes(int $type): void
    {
        $this->assertNull(FatalError::fromLastError([
            'type' => $type,
            'message' => 'Undefined array key "foo"',
            'file' => '/app/index.php',
            'line' => 42,
        ]));
    }

    public function testToleratesMalformedErrorArray(): void
    {
        $error = FatalError::fromLastError([
            'type' => E_ERROR,
            // message/file/line missing entirely
        ]);

        $this->assertInstanceOf(FatalError::class, $error);
        $this->assertNotSame('', $error->message);
        $this->assertNotSame('', $error->file);
        $this->assertSame(0, $error->line);
    }

    public function testFallsBackWhenMessageAndFileAreNotStrings(): void
    {
        $error = FatalError::fromLastError([
            'type' => E_ERROR,
            'message' => ['not', 'a', 'string'],
            'file' => 123,
            'line' => 'not an int',
        ]);

        $this->assertInstanceOf(FatalError::class, $error);
        $this->assertSame('unknown error', $error->message);
        $this->assertSame('unknown file', $error->file);
        $this->assertSame(0, $error->line);
    }

    public function testReturnsNullWhenTypeIsMissingOrNotAnInteger(): void
    {
        $this->assertNull(FatalError::fromLastError(['message' => 'no type']));
        $this->assertNull(FatalError::fromLastError(['type' => 'E_ERROR', 'message' => 'string type']));
    }

    public function testFingerprintIsStableForTheSameFault(): void
    {
        $first = FatalError::fromLastError([
            'type' => E_ERROR, 'message' => 'boom', 'file' => '/app/a.php', 'line' => 10,
        ]);
        $second = FatalError::fromLastError([
            'type' => E_ERROR, 'message' => 'boom', 'file' => '/app/a.php', 'line' => 10,
        ]);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($first->fingerprint(), $second->fingerprint());
    }

    public function testFingerprintDiffersForDifferentFaults(): void
    {
        $memory = FatalError::fromLastError([
            'type' => E_ERROR, 'message' => 'out of memory', 'file' => '/app/a.php', 'line' => 10,
        ]);
        $parse = FatalError::fromLastError([
            'type' => E_PARSE, 'message' => 'syntax error', 'file' => '/app/b.php', 'line' => 99,
        ]);

        $this->assertNotNull($memory);
        $this->assertNotNull($parse);
        $this->assertNotSame($memory->fingerprint(), $parse->fingerprint());
    }

    public function testDescriptionIncludesTheDiagnosticDetail(): void
    {
        $error = FatalError::fromLastError([
            'type' => E_ERROR, 'message' => 'boom', 'file' => '/app/a.php', 'line' => 10,
        ]);

        $this->assertNotNull($error);
        $description = $error->describe('/plants/rosa');

        $this->assertStringContainsString('boom', $description);
        $this->assertStringContainsString('/app/a.php', $description);
        $this->assertStringContainsString('10', $description);
        $this->assertStringContainsString('/plants/rosa', $description);
    }
}
