<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\EmailSender;
use BSBI\WebBase\helpers\MemberPasswordGenerator;
use BSBI\WebBase\helpers\PanelPasswordResetService;
use Kirby\Cms\App;
use Kirby\Exception\NotFoundException;
use Kirby\Exception\PermissionException;
use Kirby\Filesystem\Dir;
use PHPUnit\Framework\TestCase;

/**
 * An admin resets a user's password from the Panel's `memberpassword` field
 * (bsbi-web#869): the new password becomes the real one and is recorded in
 * that field, and the user can be emailed it.
 *
 * Only admins may do it, and only for users whose blueprint has the field: a
 * site opts accounts in by putting the field on their blueprint, so the route
 * can't be turned on other accounts, such as other admins.
 */
final class PanelPasswordResetServiceTest extends TestCase
{
    private const string ADMIN = 'admin@test.invalid';
    private const string MEMBER = 'member@test.invalid';
    private const string EDITOR = 'editor@test.invalid';
    private const string PLAIN = 'plain@test.invalid';

    private static App $kirby;
    private static string $root;

    /** @var list<array<string, mixed>> */
    private array $sent = [];
    private bool $mailWorks = true;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$root = sys_get_temp_dir() . '/kirby-base-password-reset-' . uniqid();
        mkdir(self::$root . '/content', 0777, true);
        mkdir(self::$root . '/accounts', 0777, true);

        $passwordSection = [
            'sections' => [
                'account' => [
                    'type'   => 'fields',
                    'fields' => ['displayPassword' => ['type' => 'memberpassword', 'label' => 'Password']],
                ],
            ],
        ];

        self::$kirby = new App([
            'roots' => [
                'index'    => self::$root,
                'content'  => self::$root . '/content',
                'accounts' => self::$root . '/accounts',
                'logs'     => self::$root . '/logs',
            ],
            'options' => [
                'memberPasswords' => ['email' => ['from' => 'web@test.invalid', 'subject' => 'Your new password']],
            ],
            'fields' => [
                'memberpassword' => require dirname(__DIR__, 3) . '/fields/memberpassword.php',
            ],
            'blueprints' => [
                'users/member' => ['name' => 'member', 'title' => 'Member'] + $passwordSection,
                'users/editor' => ['name' => 'editor', 'title' => 'Editor'] + $passwordSection,
                'users/plain'  => ['name' => 'plain', 'title' => 'Plain'],
            ],
        ]);

        self::$kirby->impersonate('kirby', function (): void {
            self::$kirby->users()->create(['email' => self::ADMIN, 'role' => 'admin', 'password' => 'admin-password-1']);
            self::$kirby->users()->create(['email' => self::MEMBER, 'role' => 'member', 'password' => 'old-password-1', 'content' => ['displayPassword' => 'old-password-1']]);
            self::$kirby->users()->create(['email' => self::EDITOR, 'role' => 'editor', 'password' => 'old-password-1']);
            self::$kirby->users()->create(['email' => self::PLAIN, 'role' => 'plain', 'password' => 'old-password-1']);
        });
    }

    public static function tearDownAfterClass(): void
    {
        Dir::remove(self::$root);
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::$kirby->impersonate(self::ADMIN);
    }

    protected function tearDown(): void
    {
        self::$kirby->impersonate();
        parent::tearDown();
    }

    public function testAdminResetSetsAndRecordsTheNewPassword(): void
    {
        $result = $this->service()->reset($this->id(self::MEMBER), false);

        $this->assertMatchesRegularExpression('/^[A-Za-z]+[-_*][A-Za-z]+[-_*][A-Za-z]+$/', $result['password']);
        $this->assertTrue($this->passwordOnDisk(self::MEMBER, $result['password']));
        $this->assertSame($result['password'], $this->storedField(self::MEMBER));
        $this->assertFalse($result['emailed']);
        $this->assertSame([], $this->sent);
    }

    public function testAdminResetCanEmailTheUser(): void
    {
        $result = $this->service()->reset($this->id(self::MEMBER), true);

        $this->assertTrue($result['emailed']);
        $this->assertCount(1, $this->sent);
        $this->assertSame('password-reset-by-admin', $this->sent[0]['template']);
        $this->assertSame(self::MEMBER, $this->sent[0]['to']);
        $this->assertSame('web@test.invalid', $this->sent[0]['from']);
        $this->assertSame('Your new password', $this->sent[0]['subject']);
        $this->assertIsArray($this->sent[0]['data']);
        $this->assertSame($result['password'], $this->sent[0]['data']['password']);
    }

    public function testAFailedEmailKeepsTheReset(): void
    {
        $this->mailWorks = false;

        $result = $this->service()->reset($this->id(self::MEMBER), true);

        $this->assertFalse($result['emailed']);
        $this->assertTrue($this->passwordOnDisk(self::MEMBER, $result['password']));
    }

    public function testTheFieldsOwnNameIsWhereThePasswordIsRecorded(): void
    {
        // The editor blueprint here carries the same field: any role a site
        // opts in works, whatever it is called.
        $result = $this->service()->reset($this->id(self::EDITOR), false);

        $this->assertSame($result['password'], $this->storedField(self::EDITOR));
    }

    public function testAPanelSaveCannotWriteTheStoredPassword(): void
    {
        // The Panel saves through the form without forcing, as Kirby's changes
        // API does; the field is always disabled, so typed input is ignored.
        $before = $this->storedField(self::MEMBER);
        $user = self::$kirby->users()->find(self::MEMBER);
        $this->assertNotNull($user);

        $form = \Kirby\Form\Form::for($user);
        $form->submit(['displayPassword' => 'typed-by-hand']);

        $this->assertSame($before, $form->toStoredValues()['displaypassword'] ?? null);
    }

    public function testAForcedUpdateCannotWriteTheStoredPasswordEither(): void
    {
        $before = $this->storedField(self::MEMBER);
        $user = self::$kirby->users()->find(self::MEMBER);
        $this->assertNotNull($user);

        self::$kirby->impersonate('kirby', fn () => $user->update(['displayPassword' => 'typed-by-hand', 'name' => 'Renamed']));

        $this->assertSame($before, $this->storedField(self::MEMBER));
    }

    public function testANewUserKeepsThePasswordItWasCreatedWith(): void
    {
        // A members sync creates accounts with the password already recorded.
        self::$kirby->impersonate('kirby', function (): void {
            self::$kirby->users()->create([
                'email' => 'new@test.invalid', 'role' => 'member', 'password' => 'new-password-1',
                'content' => ['displayPassword' => 'new-password-1'],
            ]);
        });

        $this->assertSame('new-password-1', $this->storedField('new@test.invalid'));
    }

    public function testPendingChangesGetTheNewPasswordToo(): void
    {
        $user = self::$kirby->users()->find(self::MEMBER);
        $this->assertNotNull($user);
        // Someone's unsaved edits hold a snapshot of the field (and their lock).
        $user->version('changes')->save([...$user->version('latest')->read(), 'displayPassword' => 'stale-copy']);

        $result = $this->service()->reset($this->id(self::MEMBER), false);

        $this->assertSame($result['password'], $user->version('changes')->read()['displaypassword'] ?? null);
        $user->version('changes')->delete();
    }

    public function testNonAdminIsRefused(): void
    {
        self::$kirby->impersonate(self::EDITOR);

        $this->expectException(PermissionException::class);
        $this->service()->reset($this->id(self::MEMBER), false);
    }

    public function testNobodyLoggedInIsRefused(): void
    {
        self::$kirby->impersonate();

        $this->expectException(PermissionException::class);
        $this->service()->reset($this->id(self::MEMBER), false);
    }

    public function testUserWithoutTheFieldIsRefused(): void
    {
        $this->expectException(PermissionException::class);
        $this->service()->reset($this->id(self::PLAIN), false);
    }

    public function testAdminAccountsAreRefused(): void
    {
        $this->expectException(PermissionException::class);
        $this->service()->reset($this->id(self::ADMIN), false);
    }

    public function testUnknownUserIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->service()->reset('nobody', false);
    }

    public function testEveryResetIsLoggedWithoutThePassword(): void
    {
        $result = $this->service()->reset($this->id(self::MEMBER), true);

        $log = (string) file_get_contents(self::$root . '/logs/password-resets.log');
        $this->assertStringContainsString(self::ADMIN . ' reset the password of ' . self::MEMBER . ' (emailed)', $log);
        $this->assertStringNotContainsString($result['password'], $log);
    }

    public function testEmailTemplatesShowTheLoginDetails(): void
    {
        $data = [
            'name' => 'A <Member>', 'email' => self::MEMBER, 'password' => 'Acer-Iris-Poa',
            'siteName' => 'Test site', 'loginUrl' => 'https://example.test/login',
        ];
        $dir = dirname(__DIR__, 3) . '/templates/emails/';

        foreach (['html', 'text'] as $type) {
            $body = \Kirby\Toolkit\Tpl::load($dir . 'password-reset-by-admin.' . $type . '.php', $data);
            $this->assertStringContainsString('Acer-Iris-Poa', $body, $type);
            $this->assertStringContainsString(self::MEMBER, $body, $type);
            $this->assertStringContainsString('https://example.test/login', $body, $type);
        }
        $html = \Kirby\Toolkit\Tpl::load($dir . 'password-reset-by-admin.html.php', $data);
        $this->assertStringContainsString('A &lt;Member&gt;', $html);
    }

    private function service(): PanelPasswordResetService
    {
        $test = $this;
        $mailer = new class ($test) implements EmailSender {
            public function __construct(private PanelPasswordResetServiceTest $test)
            {
            }

            public function send(string $template, string $from, string $replyTo, string $to, string $subject, array $data): bool
            {
                return $this->test->recordSend(compact('template', 'from', 'replyTo', 'to', 'subject', 'data'));
            }
        };

        return new PanelPasswordResetService(self::$kirby, new MemberPasswordGenerator(), $mailer);
    }

    /**
     * @param array<string, mixed> $message
     */
    public function recordSend(array $message): bool
    {
        if (!$this->mailWorks) {
            return false;
        }
        $this->sent[] = $message;
        return true;
    }

    private function id(string $email): string
    {
        $user = self::$kirby->users()->find($email);
        $this->assertNotNull($user);
        return $user->id();
    }

    private function passwordOnDisk(string $email, string $password): bool
    {
        $user = self::$kirby->users()->find($email);
        $this->assertNotNull($user);
        // The cached user object keeps the old hash; check what was written.
        return password_verify($password, (string) file_get_contents($user->root() . '/.htpasswd'));
    }

    private function storedField(string $email): string
    {
        $user = self::$kirby->users()->find($email);
        $this->assertNotNull($user);
        return (string) $user->content()->get('displayPassword')->value();
    }
}
