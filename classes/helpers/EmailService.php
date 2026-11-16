<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

use Kirby\Cms\App;
use Kirby\Toolkit\Str;
use Throwable;

/**
 * Sends one templated email, extracted from {@see KirbyBaseHelper::sendEmail()}
 * so a standalone service (one that cannot extend KirbyBaseHelper, because its
 * global-state constructor is why it cannot be unit tested) can send mail too.
 *
 * Behaviour is unchanged from the original: a send suppressed by the
 * environment or rejected by the mailer both return false — a caller asking
 * "did this person receive it?" gets the same answer either way. A caller
 * that wants to explain *why* nothing went should check the environment
 * before calling.
 */
final readonly class EmailService implements EmailSender
{
    /**
     * @param App $kirby The Kirby application (mailer, options, logging root)
     */
    public function __construct(private App $kirby)
    {
    }

    /**
     * @param string $template The email template name
     * @param string $from The sender address
     * @param string $replyTo The reply-to address
     * @param string $to One address, or several separated by commas
     * @param string $subject The subject line
     * @param array<string, mixed> $data Values the template renders from
     * @return bool True only when the message reached the mailer; false when it
     *              was rejected or suppressed by the environment
     */
    public function send(
        string $template,
        string $from,
        string $replyTo,
        string $to,
        string $subject,
        array $data
    ): bool {
        if (!$this->sendsEmail()) {
            return false;
        }

        $recipients = str_contains($to, ',') ? Str::split($to) : $to;

        try {
            $this->kirby->email([
                'template' => $template,
                'from' => $from,
                'replyTo' => $replyTo,
                'to' => $recipients,
                'subject' => $subject,
                'data' => $data,
            ]);
        } catch (Throwable $error) {
            KirbyBaseHelper::writeToLogFile('errors', $error->getMessage());
            return false;
        }

        return true;
    }

    /**
     * Whether this environment sends outbound email at all.
     *
     * @return bool True when email may be sent from here
     */
    private function sendsEmail(): bool
    {
        $host = $_SERVER['HTTP_HOST'] ?? '';

        return self::environmentAllowsEmail(
            $this->kirby->option('environment.type'),
            $this->kirby->option('environment'),
            is_string($host) ? $host : '',
        );
    }

    /**
     * Whether email may be sent, given the configured environment and the request
     * host. Shared with {@see KirbyBaseHelper::environmentSendsEmail()}.
     *
     * Two independent refusals, so neither can switch the other off:
     *
     * - A developer environment: `dev` or `local` (any case), from
     *   `environment.type` or a flat `environment` string. A site may instead
     *   resolve `environment` to an object (capsella does); then only the host
     *   check applies.
     * - A request to a loopback host (localhost, 127.0.0.1, [::1]), whatever the
     *   environment says. Before, any configured environment skipped this check,
     *   so a local copy set to `dev` sent real email.
     *
     * Anything else sends, including CLI and cron runs with no host.
     *
     * @param mixed $type The `environment.type` option
     * @param mixed $environment The `environment` option
     * @param string $host The request's HTTP host, or '' with none
     * @return bool True when email may be sent
     */
    public static function environmentAllowsEmail(mixed $type, mixed $environment, string $host): bool
    {
        foreach ([$type, $environment] as $configured) {
            if (is_string($configured) && in_array(strtolower($configured), ['dev', 'local'], true)) {
                return false;
            }
        }

        $hostName = strtolower(preg_replace('/:\d+$/', '', $host) ?? $host);

        return !in_array($hostName, ['localhost', '127.0.0.1', '[::1]', '::1'], true)
            && !str_ends_with($hostName, '.localhost');
    }
}
