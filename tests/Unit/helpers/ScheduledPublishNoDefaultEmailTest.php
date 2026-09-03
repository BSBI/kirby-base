<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\EmailSender;
use BSBI\WebBase\helpers\ScheduledPublishService;
use DateTimeImmutable;
use DateTimeZone;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Data\Yaml;
use PHPUnit\Framework\TestCase;

/**
 * The `defaultEmail`-not-configured confirmation-email fallback, in its own
 * class for the same reason as ScheduledPublishTokenTest: Kirby's App
 * registers itself as a global singleton at construction, and permission
 * checks (unlike simple option reads) resolve via that singleton rather than
 * whichever instance a test happens to hold a reference to — so a second App
 * with different options must get its own class, not share one with
 * ScheduledPublishServiceTest's already-registered App.
 */
final class ScheduledPublishNoDefaultEmailTest extends TestCase
{
    private static App $kirby;

    public static function setUpBeforeClass(): void
    {
        $root = sys_get_temp_dir() . '/kirby-base-scheduled-publish-no-default-email-' . uniqid();
        mkdir($root . '/content', 0777, true);
        mkdir($root . '/cache', 0777, true);

        self::$kirby = new App([
            'roots' => [
                'index'   => $root,
                'content' => $root . '/content',
                'cache'   => $root . '/cache',
            ],
            'users' => [['id' => 'editor-a', 'email' => 'editor-a@example.org', 'role' => 'admin']],
        ]);
    }

    public function testNoEmailSentAndNoThrowWhenDefaultEmailIsNotConfigured(): void
    {
        $kirby = self::$kirby;

        $page = $kirby->impersonate('kirby', fn (): Page => $kirby->site()->createChild([
            'slug' => 'no-default-email-page',
            'template' => 'default',
            'content' => ['title' => 'no-default-email-page'],
        ]));
        $kirby->impersonate('kirby', fn () => $kirby->site()->update([
            'scheduled' => Yaml::encode([[
                'page' => [$page->uuid()->toString()],
                'scheduledPublishDate' => '2026-09-03',
                'scheduledPublishTime' => '11:00:00',
                'scheduledBy' => 'editor-a',
            ]]),
        ]));

        $emailService = $this->createMock(EmailSender::class);
        $emailService->expects($this->never())->method('send');

        $now = new DateTimeImmutable('2026-09-03 12:00:00', new DateTimeZone('Europe/London'));
        $result = (new ScheduledPublishService($kirby, $emailService))->run($now);

        $this->assertStringContainsString('Published 1', $result);
    }
}
