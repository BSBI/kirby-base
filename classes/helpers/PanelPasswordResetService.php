<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

use Kirby\Cms\App;
use Kirby\Cms\User;
use Kirby\Exception\NotFoundException;
use Kirby\Exception\PermissionException;

/**
 * Resets a user's password from the Panel's `memberpassword` field
 * (bsbi-web#869): a new generated password becomes the real one, is recorded
 * in the field, and can be emailed to the user.
 *
 * Who may do it, and to whom:
 *  - only an admin;
 *  - only users whose blueprint carries a `memberpassword` field. Putting the
 *    field on a blueprint is how a site opts that role in, so the route can't
 *    be turned on accounts the site never meant it for, such as other admins.
 *    Admin accounts are refused outright as well.
 *
 * Every reset is logged to `password-resets.log` (who, whom, whether
 * emailed), never the password itself.
 */
final readonly class PanelPasswordResetService
{
    public const string EMAIL_TEMPLATE = 'password-reset-by-admin';

    /**
     * @param App $kirby The Kirby application
     * @param MemberPasswordGenerator $generator Makes the new password
     * @param EmailSender $mailer Sends the optional email to the user
     */
    public function __construct(
        private App $kirby,
        private MemberPasswordGenerator $generator,
        private EmailSender $mailer
    ) {
    }

    /**
     * The service as the API route uses it, configured from site options.
     *
     * @param App $kirby The Kirby application
     * @return self
     */
    public static function fromKirby(App $kirby): self
    {
        return new self(
            $kirby,
            MemberPasswordGenerator::fromOptions($kirby->option('memberPasswords.words')),
            new EmailService($kirby)
        );
    }

    /**
     * @param string $userId The Kirby id of the user to reset
     * @param bool $notify Whether to email the user their new password
     * @return array{password: string, emailed: bool}
     * @throws PermissionException If the current user isn't an admin, or the
     *                             target can't be reset this way
     * @throws NotFoundException If there is no such user
     */
    public function reset(string $userId, bool $notify): array
    {
        $actor = $this->kirby->user();
        if (!$actor instanceof User || !$actor->isAdmin() || $actor->isKirby()) {
            throw new PermissionException('Only admins can reset passwords.');
        }

        $user = $this->kirby->users()->find($userId);
        if (!$user instanceof User) {
            throw new NotFoundException('No such user.');
        }

        if (StoredPassword::fieldName($user) === null || $user->isAdmin()) {
            throw new PermissionException('This account\'s password can\'t be reset here.');
        }

        $password = $this->generator->generate();

        $this->kirby->impersonate('kirby', fn () => $user->changePassword($password));
        StoredPassword::record($user, $password);

        $emailed = $notify && $this->email($user, $password);

        $outcome = $emailed ? ' (emailed)' : ($notify ? ' (email failed)' : '');
        KirbyBaseHelper::writeToLogFile(
            'password-resets',
            $actor->email() . ' reset the password of ' . $user->email() . $outcome
        );

        return ['password' => $password, 'emailed' => $emailed];
    }

    /**
     * @param User $user The user to email
     * @param string $password Their new password
     * @return bool Whether the email reached the mailer
     */
    private function email(User $user, string $password): bool
    {
        $to = $user->email();
        if ($to === null || $to === '') {
            KirbyBaseHelper::writeToLogFile('errors', 'Password reset email not sent: ' . $user->id() . ' has no email address.');
            return false;
        }
        $from = $this->stringOption('memberPasswords.email.from') ?? $this->stringOption('defaultEmail');
        if ($from === null) {
            KirbyBaseHelper::writeToLogFile('errors', 'Password reset email not sent: set memberPasswords.email.from.');
            return false;
        }

        return $this->mailer->send(
            self::EMAIL_TEMPLATE,
            $from,
            $this->stringOption('memberPasswords.email.replyTo') ?? $from,
            $to,
            $this->stringOption('memberPasswords.email.subject')
                ?? 'Your new password for ' . $this->kirby->site()->title()->value(),
            [
                'name'     => $user->name()->or($user->email())->value(),
                'email'    => $user->email(),
                'password' => $password,
                'siteName' => $this->kirby->site()->title()->value(),
                'loginUrl' => $this->stringOption('memberPasswords.loginUrl') ?? $this->kirby->url() . '/login',
            ]
        );
    }

    /**
     * @param string $key The option key
     * @return string|null The option, when it is a non-empty string
     */
    private function stringOption(string $key): ?string
    {
        $value = $this->kirby->option($key);
        return is_string($value) && $value !== '' ? $value : null;
    }
}
