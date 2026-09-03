<?php

declare(strict_types = 1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\EmailService;
use Kirby\Cms\App;
use Kirby\Email\PHPMailer;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for EmailService — extracted from KirbyBaseHelper::sendEmail()
 * so a standalone service (ScheduledPublishService) can send mail too.
 *
 * Two Apps are booted once, up front (booting registers global handlers,
 * which PHPUnit flags as risky if it happens inside a test): one with
 * `environment: local` (the suppression path), one with `environment:
 * staging` and its `email` component forced into Kirby's own debug mode —
 * the mailer still validates templates and props for real, it just never
 * touches the network, which is what makes "reached the mailer" safe to
 * assert on in any environment, including CI.
 */
final class EmailServiceTest extends TestCase
{
    private static App $local;

    private static App $staging;

    public static function setUpBeforeClass(): void
    {
        $root = sys_get_temp_dir() . '/kirby-base-email-service-' . uniqid();
        $templatesRoot = $root . '/templates';
        mkdir($templatesRoot . '/emails', 0777, true);
        mkdir($root . '/content', 0777, true);
        mkdir($root . '/cache', 0777, true);

        file_put_contents(
            $templatesRoot . '/emails/test-email.text.php',
            "<?php if (!isset(\$data['greeting'])) :\n"
            . "    throw new Exception('greeting not supplied');\n"
            . "endif; ?>Hello <?= \$data['greeting'] ?>"
        );

        $roots = [
            'index'     => $root,
            'content'   => $root . '/content',
            'cache'     => $root . '/cache',
            'templates' => $templatesRoot,
        ];

        self::$local = new App([
            'roots' => $roots,
            'options' => ['environment' => 'local'],
        ]);

        self::$staging = new App([
            'roots' => $roots,
            'options' => ['environment' => 'staging'],
            'components' => [
                'email' => fn (App $kirby, array $props = [], bool $debug = false) => new PHPMailer($props, true),
            ],
        ]);
    }

    public function testReturnsFalseAndSendsNothingWhenEnvironmentIsLocal(): void
    {
        $service = new EmailService(self::$local);

        $sent = $service->send(
            template: 'test-email',
            from: 'from@example.org',
            replyTo: 'reply@example.org',
            to: 'to@example.org',
            subject: 'Test',
            data: ['greeting' => 'World'],
        );

        $this->assertFalse($sent);
    }

    public function testReturnsTrueAndReachesTheMailerWhenSendingIsAllowed(): void
    {
        $service = new EmailService(self::$staging);

        $sent = $service->send(
            template: 'test-email',
            from: 'from@example.org',
            replyTo: 'reply@example.org',
            to: 'to@example.org',
            subject: 'Test',
            data: ['greeting' => 'World'],
        );

        $this->assertTrue($sent);
    }

    public function testSplitsACommaSeparatedRecipientList(): void
    {
        $service = new EmailService(self::$staging);

        // A malformed template would throw either way; this only proves the
        // call gets past recipient handling and into the mailer.
        $sent = $service->send(
            template: 'test-email',
            from: 'from@example.org',
            replyTo: 'reply@example.org',
            to: 'one@example.org,two@example.org',
            subject: 'Test',
            data: ['greeting' => 'World'],
        );

        $this->assertTrue($sent);
    }

    public function testCatchesAFailureLogsItAndReturnsFalse(): void
    {
        $service = new EmailService(self::$staging);

        // Missing required template var throws inside the template — the
        // service must catch it, not let it propagate.
        $sent = $service->send(
            template: 'test-email',
            from: 'from@example.org',
            replyTo: 'reply@example.org',
            to: 'to@example.org',
            subject: 'Test',
            data: [],
        );

        $this->assertFalse($sent);
    }

    public function testUnknownTemplateIsCaughtAndReturnsFalse(): void
    {
        $service = new EmailService(self::$staging);

        $sent = $service->send(
            template: 'does-not-exist',
            from: 'from@example.org',
            replyTo: 'reply@example.org',
            to: 'to@example.org',
            subject: 'Test',
            data: [],
        );

        $this->assertFalse($sent);
    }
}
