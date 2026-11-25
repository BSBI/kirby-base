<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\EmailService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Whether an environment sends outbound email: never from a developer's machine,
 * whatever the configured environment says.
 */
final class EmailEnvironmentTest extends TestCase
{
    /**
     * @return array<string, array{mixed, mixed, string, bool}>
     */
    public static function environments(): array
    {
        return [
            'environment.type dev (bsbi-web local copy)' => ['dev', null, 'example.org', false],
            'environment.type local (UI test site)'      => ['local', null, 'example.org', false],
            'flat environment dev'                       => [null, 'dev', 'example.org', false],
            'staging sends'                              => ['staging', null, 'staging.example.org', true],
            'live (no environment) sends'                => [null, null, 'bsbi.org', true],
            'cron with no host sends'                    => [null, null, '', true],
            'localhost refuses with no environment'      => [null, null, 'localhost:8092', false],
            'localhost refuses even when set to staging' => ['staging', null, 'localhost:8092', false],
            '127.0.0.1 refuses'                          => [null, null, '127.0.0.1:8093', false],
            'IPv6 loopback refuses'                      => [null, null, '[::1]:8092', false],
            'a resolved environment object falls to the host check' => [new \stdClass(), null, 'bsbi.org', true],
            'case-insensitive environment'               => ['DEV', null, 'example.org', false],
            // #87: capsella's Environment object, under PHPUnit or a CLI script
            'an environment object that refuses, with no host' => [null, self::environmentObject(false), '', false],
            'an environment object that refuses, on a real host' => [null, self::environmentObject(false), 'bsbi.org', false],
            'an environment object that allows, cron with no host' => [null, self::environmentObject(true), '', true],
            'an environment object that allows, still refused on localhost' => [null, self::environmentObject(true), 'localhost:8095', false],
            'an object without sendsEmail(), with no host'  => [null, new \stdClass(), '', true],
        ];
    }

    /**
     * A stand-in for a site's resolved environment object, such as capsella's.
     *
     * @param bool $sends What its sendsEmail() answers
     * @return object
     */
    private static function environmentObject(bool $sends): object
    {
        return new class ($sends) {
            /**
             * @param bool $sends What sendsEmail() answers
             */
            public function __construct(private readonly bool $sends)
            {
            }

            /**
             * @return bool
             */
            public function sendsEmail(): bool
            {
                return $this->sends;
            }
        };
    }

    #[DataProvider('environments')]
    public function testSendsEmailOnlyAwayFromDeveloperMachines(mixed $type, mixed $environment, string $host, bool $expected): void
    {
        $this->assertSame($expected, EmailService::environmentAllowsEmail($type, $environment, $host));
    }
}
