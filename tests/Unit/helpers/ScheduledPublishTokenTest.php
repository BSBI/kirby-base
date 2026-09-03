<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\ScheduledPublishService;
use BSBI\WebBase\Testing\KirbyTestEnvironment;
use Kirby\Cms\App;
use PHPUnit\Framework\TestCase;

/**
 * Token-guard tests for ScheduledPublishService.
 *
 * In their own class because the `scheduledPublish.token` option must be set
 * at boot, and booting an App mid-test registers global handlers PHPUnit
 * rightly flags as risky. The token-free behaviour is covered in
 * {@see ScheduledPublishServiceTest}.
 */
final class ScheduledPublishTokenTest extends TestCase
{
    private static App $kirby;

    public static function setUpBeforeClass(): void
    {
        self::$kirby = KirbyTestEnvironment::boot(
            'kirby-base-scheduled-token-' . uniqid(),
            ['scheduledPublish' => ['token' => 'cron-secret']]
        );
    }

    public function testAuthoriseAcceptsTheConfiguredToken(): void
    {
        $this->assertTrue($this->service()->authorise('cron-secret'));
    }

    public function testAuthoriseRejectsAWrongOrMissingToken(): void
    {
        $this->assertFalse($this->service()->authorise('wrong'));
        $this->assertFalse($this->service()->authorise(null));
        $this->assertFalse($this->service()->authorise(''));
    }

    private function service(): ScheduledPublishService
    {
        return new ScheduledPublishService(self::$kirby);
    }
}
