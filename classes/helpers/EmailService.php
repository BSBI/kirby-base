<?php

declare(strict_types = 1);

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
     * Whether this environment sends outbound email at all. See
     * {@see KirbyBaseHelper::environmentSendsEmail()} for the full rationale
     * (three-source lookup, fail-closed on localhost); identical behaviour.
     *
     * @return bool True when email may be sent from here
     */
    private function sendsEmail(): bool
    {
        foreach ([$this->kirby->option('environment.type'), $this->kirby->option('environment')] as $configured) {
            if (is_string($configured) && $configured !== '') {
                return $configured !== 'local';
            }
        }

        $host = $_SERVER['HTTP_HOST'] ?? '';

        return !str_starts_with(is_string($host) ? $host : '', 'localhost');
    }
}
