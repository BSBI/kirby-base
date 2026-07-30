<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\KirbyFieldReader;
use BSBI\WebBase\helpers\UserService;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use PHPUnit\Framework\TestCase;

/**
 * Tests for UserService.
 *
 * A minimal Kirby App is created once per class with admin, editor, and member
 * roles registered. Pages are built in-memory via Page::factory() and users
 * via \Kirby\Cms\User::factory().
 *
 * The $hasSessionCookieFn closure is injected directly so tests can control
 * the cookie-present / cookie-absent paths without needing a real HTTP session.
 *
 * Methods that require a fully authenticated $kirby->user() (e.g. the
 * "cookie present + admin logged in" path of isCurrentUserAdminOrEditor) are
 * not tested here because they depend on Kirby's live session mechanism.
 */
final class UserServiceTest extends TestCase
{
    private static App $kirby;
    private static KirbyFieldReader $fieldReader;
    private static string $tmpDir;

    public static function setUpBeforeClass(): void
    {
        self::$tmpDir = sys_get_temp_dir() . '/kirby-user-service-test';
        $contentDir  = self::$tmpDir . '/content';

        if (!is_dir($contentDir)) {
            mkdir($contentDir, 0777, true);
        }

        file_put_contents(
            $contentDir . '/site.txt',
            "Title: Test Site\n"
        );

        self::$kirby = new App([
            'roots' => [
                'index'   => self::$tmpDir,
                'content' => $contentDir,
            ],
            'roles' => [
                ['name' => 'admin',  'title' => 'Admin'],
                ['name' => 'editor', 'title' => 'Editor'],
                ['name' => 'member', 'title' => 'Member'],
            ],
        ]);

        self::$fieldReader = new KirbyFieldReader(self::$kirby, self::$kirby->site());
    }

    public static function tearDownAfterClass(): void
    {
        @unlink(self::$tmpDir . '/content/site.txt');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeService(bool $hasSessionCookie = false): UserService
    {
        return new UserService(
            self::$kirby,
            self::$fieldReader,
            fn () => $hasSessionCookie,
        );
    }

    private function makePage(array $content = [], string $template = 'default'): Page
    {
        return Page::factory([
            'slug'     => 'test-' . uniqid(),
            'template' => $template,
            'content'  => $content,
        ]);
    }

    private function makeKirbyUser(string $role): \Kirby\Cms\User
    {
        return \Kirby\Cms\User::factory([
            'email' => 'test-' . uniqid() . '@example.com',
            'role'  => $role,
        ]);
    }

    // =========================================================================
    // isUserAdminOrEditor
    // =========================================================================

    public function testIsUserAdminOrEditorReturnsFalseForNull(): void
    {
        $this->assertFalse($this->makeService()->isUserAdminOrEditor(null));
    }

    public function testIsUserAdminOrEditorReturnsTrueForAdmin(): void
    {
        $this->assertTrue($this->makeService()->isUserAdminOrEditor($this->makeKirbyUser('admin')));
    }

    public function testIsUserAdminOrEditorReturnsTrueForEditor(): void
    {
        $this->assertTrue($this->makeService()->isUserAdminOrEditor($this->makeKirbyUser('editor')));
    }

    public function testIsUserAdminOrEditorReturnsFalseForMember(): void
    {
        $this->assertFalse($this->makeService()->isUserAdminOrEditor($this->makeKirbyUser('member')));
    }

    // =========================================================================
    // isUserAdmin
    // =========================================================================

    public function testIsUserAdminReturnsFalseForNull(): void
    {
        $this->assertFalse($this->makeService()->isUserAdmin(null));
    }

    public function testIsUserAdminReturnsTrueForAdmin(): void
    {
        $this->assertTrue($this->makeService()->isUserAdmin($this->makeKirbyUser('admin')));
    }

    /**
     * Editors are admins for isUserAdminOrEditor but must not be here — that is the
     * whole point of the narrower predicate.
     */
    public function testIsUserAdminReturnsFalseForEditor(): void
    {
        $this->assertFalse($this->makeService()->isUserAdmin($this->makeKirbyUser('editor')));
    }

    public function testIsUserAdminReturnsFalseForMember(): void
    {
        $this->assertFalse($this->makeService()->isUserAdmin($this->makeKirbyUser('member')));
    }

    // =========================================================================
    // isCurrentUserAdminOrEditor
    // =========================================================================

    public function testIsCurrentUserAdminOrEditorReturnsFalseWhenNoSessionCookie(): void
    {
        $this->assertFalse($this->makeService(false)->isCurrentUserAdminOrEditor());
    }

    public function testIsCurrentUserAdminOrEditorReturnsFalseWhenCookieExistsButNoUserLoggedIn(): void
    {
        // Cookie present but kirby->user() returns null in the test environment
        $this->assertFalse($this->makeService(true)->isCurrentUserAdminOrEditor());
    }

    // =========================================================================
    // isCurrentUserAdminOrEditorOrHasRoles
    // =========================================================================

    public function testIsCurrentUserAdminOrEditorOrHasRolesReturnsFalseWhenNoSessionCookie(): void
    {
        $this->assertFalse($this->makeService(false)->isCurrentUserAdminOrEditorOrHasRoles(['member']));
    }

    public function testIsCurrentUserAdminOrEditorOrHasRolesReturnsFalseWithCookieButNoUser(): void
    {
        $this->assertFalse($this->makeService(true)->isCurrentUserAdminOrEditorOrHasRoles(['member']));
    }

    // =========================================================================
    // isUserLoggedIn
    // =========================================================================

    public function testIsUserLoggedInReturnsFalseWhenNoSessionCookie(): void
    {
        $this->assertFalse($this->makeService(false)->isUserLoggedIn());
    }

    public function testIsUserLoggedInReturnsFalseWhenCookieExistsButNoUserLoggedIn(): void
    {
        // Cookie present but kirby->user() returns null in the test environment
        $this->assertFalse($this->makeService(true)->isUserLoggedIn());
    }

    // =========================================================================
    // doesCurrentUserHaveRole
    // =========================================================================

    public function testDoesCurrentUserHaveRoleReturnsFalseWhenNoSessionCookie(): void
    {
        $this->assertFalse($this->makeService(false)->doesCurrentUserHaveRole('admin'));
    }

    public function testDoesCurrentUserHaveRoleReturnsFalseWhenCookieExistsButNoUserLoggedIn(): void
    {
        $this->assertFalse($this->makeService(true)->doesCurrentUserHaveRole('admin'));
    }

    // =========================================================================
    // getCurrentUserName / getCurrentUserRole
    // =========================================================================

    public function testGetCurrentUserNameReturnsEmptyStringWhenNoSessionCookie(): void
    {
        $this->assertSame('', $this->makeService(false)->getCurrentUserName());
    }

    public function testGetCurrentUserNameReturnsEmptyStringWhenCookieExistsButNoUserLoggedIn(): void
    {
        $this->assertSame('', $this->makeService(true)->getCurrentUserName());
    }

    public function testGetCurrentUserRoleReturnsEmptyStringWhenNoSessionCookie(): void
    {
        $this->assertSame('', $this->makeService(false)->getCurrentUserRole());
    }

    public function testGetCurrentUserRoleReturnsEmptyStringWhenCookieExistsButNoUserLoggedIn(): void
    {
        $this->assertSame('', $this->makeService(true)->getCurrentUserRole());
    }

    // =========================================================================
    // getCurrentUser
    // =========================================================================

    public function testGetCurrentUserReturnsEmptyUserModelWhenNoSessionCookie(): void
    {
        $user = $this->makeService(false)->getCurrentUser();

        $this->assertSame('', $user->getUserId());
        $this->assertSame('', $user->getUserName());
        $this->assertSame('', $user->getRole());
        $this->assertFalse($user->isLoggedIn());
    }

    public function testGetCurrentUserReturnsEmptyUserModelWhenCookieExistsButNoUserLoggedIn(): void
    {
        $user = $this->makeService(true)->getCurrentUser();

        $this->assertSame('', $user->getUserId());
        $this->assertFalse($user->isLoggedIn());
    }

    // =========================================================================
    // getUser
    // =========================================================================

    public function testGetUserBuildsModelWithCorrectIdRoleAndEmail(): void
    {
        $kirbyUser = $this->makeKirbyUser('member');
        $user = $this->makeService()->getUser($kirbyUser);

        $this->assertSame($kirbyUser->id(), $user->getUserId());
        $this->assertSame($kirbyUser->email(), $user->getEmail());
        $this->assertSame('member', $user->getRole());
    }

    public function testGetUserBuildsModelWithAdminRole(): void
    {
        $kirbyUser = $this->makeKirbyUser('admin');
        $user = $this->makeService()->getUser($kirbyUser);

        $this->assertSame('admin', $user->getRole());
    }

    public function testGetUserMapsJobTitle(): void
    {
        $kirbyUser = \Kirby\Cms\User::factory([
            'email'   => 'jobtitle-' . uniqid() . '@example.com',
            'role'    => 'member',
            'content' => ['jobTitle' => 'Records Officer'],
        ]);
        $user = $this->makeService()->getUser($kirbyUser);

        $this->assertTrue($user->hasJobTitle());
        $this->assertSame('Records Officer', $user->getJobTitle());
    }

    public function testGetUserWithoutJobTitleHasEmptyJobTitle(): void
    {
        $kirbyUser = $this->makeKirbyUser('member');
        $user = $this->makeService()->getUser($kirbyUser);

        $this->assertFalse($user->hasJobTitle());
        $this->assertSame('', $user->getJobTitle());
    }

    // =========================================================================
    // getUserName
    // =========================================================================

    public function testGetUserNameReturnsMalformedMessageForInvalidUri(): void
    {
        $this->assertSame('User id is malformed', $this->makeService()->getUserName('not-a-valid-uri'));
    }

    public function testGetUserNameReturnsFallbackWhenUserNotFound(): void
    {
        $result = $this->makeService()->getUserName('user://unknownId123', 'Not found');

        $this->assertSame('Not found', $result);
    }

    public function testGetUserNameReturnsDefaultFallbackWhenUserNotFound(): void
    {
        $result = $this->makeService()->getUserName('user://unknownId123');

        $this->assertSame('User not found', $result);
    }

    // =========================================================================
    // checkPagePermissions — pages that bypass role checks
    // =========================================================================

    public function testCheckPagePermissionsGrantsAccessToLoginPage(): void
    {
        $page = Page::factory(['slug' => 'login', 'template' => 'login']);

        $this->assertTrue($this->makeService(false)->checkPagePermissions($page));
    }

    public function testCheckPagePermissionsGrantsAccessToResetPasswordPage(): void
    {
        $page = Page::factory(['slug' => 'reset-password', 'template' => 'reset_password']);

        $this->assertTrue($this->makeService(false)->checkPagePermissions($page));
    }

    public function testCheckPagePermissionsGrantsAccessToResetPasswordVerificationPage(): void
    {
        $page = Page::factory(['slug' => 'verify', 'template' => 'reset_password_verification']);

        $this->assertTrue($this->makeService(false)->checkPagePermissions($page));
    }

    // =========================================================================
    // checkPagePermissions — no roles configured
    // =========================================================================

    public function testCheckPagePermissionsGrantsAccessWhenNoRolesConfiguredAndNoUser(): void
    {
        $this->assertTrue($this->makeService(false)->checkPagePermissions($this->makePage()));
    }

    // =========================================================================
    // checkPagePermissions — page-level required roles
    // =========================================================================

    public function testCheckPagePermissionsDeniesAccessWhenPageRequiresRoleAndNoCookie(): void
    {
        $page = $this->makePage(['requiredRoles' => 'member']);

        $this->assertFalse($this->makeService(false)->checkPagePermissions($page));
    }

    public function testCheckPagePermissionsDeniesAccessWhenPageRequiresRoleAndNoUserLoggedIn(): void
    {
        // Cookie present but kirby->user() returns null in the test environment
        $page = $this->makePage(['requiredRoles' => 'member']);

        $this->assertFalse($this->makeService(true)->checkPagePermissions($page));
    }

    // =========================================================================
    // checkPagePermissions — site-level required roles
    // =========================================================================

    public function testCheckPagePermissionsDeniesAccessWhenSiteRequiresRoleAndNoUser(): void
    {
        // Create a separate App/reader/service with site-level requiredRoles set
        $tmpDir    = sys_get_temp_dir() . '/kirby-user-service-site-roles-test';
        $contentDir = $tmpDir . '/content';

        if (!is_dir($contentDir)) {
            mkdir($contentDir, 0777, true);
        }

        file_put_contents(
            $contentDir . '/site.txt',
            "Title: Restricted Site\n\n----\n\nrequiredRoles: member\n"
        );

        $kirby = new App([
            'roots' => [
                'index'   => $tmpDir,
                'content' => $contentDir,
            ],
            'roles' => [
                ['name' => 'admin',  'title' => 'Admin'],
                ['name' => 'member', 'title' => 'Member'],
            ],
        ]);

        $fieldReader = new KirbyFieldReader($kirby, $kirby->site());
        $service     = new UserService($kirby, $fieldReader, fn () => false);
        $page        = Page::factory(['slug' => 'some-page', 'template' => 'default']);

        $this->assertFalse($service->checkPagePermissions($page));

        @unlink($contentDir . '/site.txt');

        // Kirby registers error/exception handlers on construction; restore them
        // so PHPUnit does not flag this test as risky.
        restore_error_handler();
        restore_exception_handler();
    }
}
