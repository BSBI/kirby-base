<?php

declare(strict_types = 1);

namespace BSBI\WebBase\helpers;

/**
 * The contract {@see EmailService} implements. Exists so a standalone
 * service (one that cannot extend KirbyBaseHelper, whose global-state
 * constructor is why it cannot be unit tested) can accept an email sender
 * via constructor injection and have tests substitute a double —
 * EmailService itself is `final`, so it cannot be mocked directly.
 */
interface EmailSender
{
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
    ): bool;
}
