<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

use BSBI\WebBase\models\User;
use Closure;
use Exception;
use Kirby\Cms\App;
use Kirby\Content\ImmutableMemoryStorage;
use Kirby\Cms\Page;
use Throwable;

/**
 * Service for user model building, permission checks, and user mutation operations.
 *
 * The $hasSessionCookieFn closure is injected from KirbyBaseHelper to allow
 * session-safe user access: calling kirby()->user() starts a PHP session,
 * which prevents page caching. Checking for a session cookie first avoids
 * this on uncached public pages.
 */
final readonly class UserService
{
    /**
     * @param App $kirby The Kirby application object
     * @param KirbyFieldReader $fieldReader For reading page/site fields in permission checks
     * @param Closure $hasSessionCookieFn fn(): bool — checks for an active session cookie
     *        without starting a new session
     */
    public function __construct(
        private App            $kirby,
        private KirbyFieldReader $fieldReader,
        private Closure        $hasSessionCookieFn,
    ) {
    }

    // region USER MODEL BUILDERS

    /**
     * Resolve a Kirby user:// URI to a username string.
     *
     * @param string $userId A Kirby user URI (e.g. "user://dvw7nX3C")
     * @param string $fallback Returned when the user is not found
     * @return string
     * @noinspection PhpUnused
     */
    public function getUserName(string $userId, string $fallback = 'User not found'): string
    {
        if (preg_match('/user:\/\/([a-zA-Z0-9]+)/', $userId, $matches)) {
            $user = $this->kirby->user($matches[1]);
            return $user ? $user->username() : $fallback;
        }
        return 'User id is malformed';
    }

    /**
     * Return the currently authenticated Kirby user object.
     *
     * @return \Kirby\Cms\User
     */
    public function getCurrentKirbyUser(): \Kirby\Cms\User
    {
        return $this->kirby->user();
    }

    /**
     * Build a User model for the currently authenticated user.
     * Returns an empty User model when no session cookie is present (to avoid
     * starting a PHP session that would break page caching).
     *
     * @return User
     */
    public function getCurrentUser(): User
    {
        $user = new User('user');
        $userLoggedIn = ($this->hasSessionCookieFn)() ? $this->kirby->user() : null;
        $userId   = $userLoggedIn ? $userLoggedIn->id() : '';
        $userName = $userLoggedIn ? $userLoggedIn->userName() : '';
        $role     = $userLoggedIn ? $userLoggedIn->role()->name() : '';
        $user->setUserId($userId)->setUserName($userName)->setRole($role);
        return $user;
    }

    /**
     * Build a User model from a Kirby user object.
     *
     * @param \Kirby\Cms\User $kirbyUser
     * @return User
     */
    public function getUser(\Kirby\Cms\User $kirbyUser): User
    {
        $user = new User('user');
        $user->setUserId($kirbyUser->id())
            ->setUserName($kirbyUser->username())
            ->setEmail($kirbyUser->email())
            ->setRole($kirbyUser->role()->name())
            ->setJobTitle($kirbyUser->content()->get('jobTitle')->value() ?? '');
        return $user;
    }

    /**
     * Get the current Kirby username, or empty string if no user is logged in.
     *
     * @return string
     * @noinspection PhpUnused
     */
    public function getCurrentUserName(): string
    {
        if (!($this->hasSessionCookieFn)()) {
            return '';
        }
        return $this->kirby->user() ? $this->kirby->user()->name()->value() : '';
    }

    /**
     * Get the current Kirby user role, or empty string if no user is logged in.
     *
     * @return string
     * @noinspection PhpUnused
     */
    public function getCurrentUserRole(): string
    {
        if (!($this->hasSessionCookieFn)()) {
            return '';
        }
        return $this->kirby->user() ? $this->kirby->user()->role()->name() : '';
    }

    // endregion

    // region PERMISSION CHECKS

    /**
     * Check whether the current user has permission to access the given page.
     * Grants access to admin/editor roles unconditionally.
     * Otherwise checks site-level and page-hierarchy requiredRoles fields.
     *
     * @param Page $currentPage
     * @return bool
     * @throws KirbyRetrievalException
     */
    public function checkPagePermissions(Page $currentPage): bool
    {
        if (in_array($currentPage->template()->name(), ['login', 'reset_password', 'reset_password_verification'])) {
            return true;
        }

        $user = ($this->hasSessionCookieFn)() ? $this->kirby->user() : null;

        if ($this->isCurrentUserAdminOrEditor()) {
            return true;
        }

        $siteRoles = $this->fieldReader->getSiteFieldAsString('requiredRoles');
        if (!empty($siteRoles)) {
            $requiredRoles = array_map('trim', explode(',', $siteRoles));
            if ((!$user || $user->isNobody()) || (!in_array($user->role()->name(), $requiredRoles))) {
                return false;
            }
        }

        $applicableRoles = [];
        $page = $currentPage;
        while ($page) {
            $currentRoles = $this->fieldReader->getPageFieldAsArray($page, 'requiredRoles');
            if (!empty($currentRoles)) {
                $applicableRoles = $currentRoles;
                break;
            }
            $page = $page->parent();
        }

        if (empty($applicableRoles)) {
            return true;
        }

        if ($user && !$user->isKirby() && in_array($user->role()->name(), $applicableRoles)) {
            return true;
        }

        return false;
    }

    /**
     * @return bool
     */
    public function isCurrentUserAdminOrEditor(): bool
    {
        if (!($this->hasSessionCookieFn)()) {
            return false;
        }
        return $this->isUserAdminOrEditor($this->kirby->user());
    }

    /**
     * @param array $roles
     * @return bool
     */
    public function isCurrentUserAdminOrEditorOrHasRoles(array $roles): bool
    {
        $currentRole = $this->getCurrentUserRole();
        return in_array($currentRole, $roles) || $currentRole === 'admin' || $currentRole === 'editor';
    }

    /**
     * @param \Kirby\Cms\User|null $user
     * @return bool
     */
    public function isUserAdminOrEditor(\Kirby\Cms\User|null $user): bool
    {
        return $user
            && !$user->isKirby()
            && ($user->role()->name() === 'admin' || $user->role()->name() === 'editor');
    }

    /**
     * Whether the given user is an administrator.
     *
     * Narrower than isUserAdminOrEditor(): some actions are for admins alone, and
     * a consumer should not have to compare role strings itself to express that.
     *
     * @param \Kirby\Cms\User|null $user The user to check, or null
     * @return bool True when the user is a real administrator
     */
    public function isUserAdmin(\Kirby\Cms\User|null $user): bool
    {
        return $user
            && !$user->isKirby()
            && $user->role()->name() === 'admin';
    }

    /**
     * Whether the signed-in user is an administrator.
     *
     * @return bool True when an administrator is signed in
     */
    public function isCurrentUserAdmin(): bool
    {
        if (!($this->hasSessionCookieFn)()) {
            return false;
        }
        return $this->isUserAdmin($this->kirby->user());
    }

    /**
     * @param string $role
     * @return bool
     */
    public function doesCurrentUserHaveRole(string $role): bool
    {
        if (!($this->hasSessionCookieFn)()) {
            return false;
        }
        $user = $this->kirby->user();
        return $user && $user->role()->name() === $role;
    }

    /**
     * @return bool
     * @noinspection PhpUnused
     */
    public function isUserLoggedIn(): bool
    {
        if (!($this->hasSessionCookieFn)()) {
            return false;
        }
        return $this->kirby->user() !== null;
    }

    // endregion

    // region USER MUTATION

    /**
     * @param array $userData
     * @return \Kirby\Cms\User
     * @throws KirbyRetrievalException
     */
    public function createUser(array $userData): \Kirby\Cms\User
    {
        try {
            return $this->kirby->impersonate('kirby', function () use ($userData) {
                return $this->kirby->users()->create($userData);
            });
        } catch (Throwable $e) {
            throw new KirbyRetrievalException($e->getMessage());
        }
    }

    /**
     * @param \Kirby\Cms\User $user
     * @param array $updateData
     * @return \Kirby\Cms\User
     * @throws KirbyRetrievalException
     */
    public function updateUser(\Kirby\Cms\User $user, array $updateData): \Kirby\Cms\User
    {
        $user = self::latestVersionOf($user);

        try {
            return $this->kirby->impersonate('kirby', function () use ($user, $updateData) {
                return $user->update($updateData);
            });
        } catch (Throwable $e) {
            throw new KirbyRetrievalException($e->getMessage());
        }
    }

    /**
     * The most recent instance of a user, following any updates already made.
     *
     * Kirby models are immutable: `$user->update()` returns a *new* object and
     * puts the old one into ImmutableMemoryStorage, which refuses every further
     * write with "Storage for the user is immutable and cannot be updated. Make
     * sure to use the last alteration of the object."
     *
     * That makes two updates to one user in a single request fail unless every
     * caller threads the returned object through by hand — and the second write
     * is often in a different method from the first, so the object that reaches
     * it is whatever the original caller was holding. Doing it here means
     * updating a user twice in a request behaves the way a caller expects,
     * rather than throwing at the second write.
     *
     * The immutable storage keeps a pointer to the instance that replaced it, so
     * this walks that chain to whatever is current. The step limit is a guard
     * against a cycle rather than an expected case: in practice the chain is one
     * or two links long.
     *
     * @param \Kirby\Cms\User $user The user, possibly superseded by an update
     * @return \Kirby\Cms\User The most recent instance of the same user
     */
    private static function latestVersionOf(\Kirby\Cms\User $user): \Kirby\Cms\User
    {
        for ($step = 0; $step < 10; $step++) {
            $storage = $user->storage();

            if (!$storage instanceof ImmutableMemoryStorage) {
                break;
            }

            $next = $storage->nextModel();

            if (!$next instanceof \Kirby\Cms\User) {
                break;
            }

            $user = $next;
        }

        return $user;
    }

    /**
     * @param \Kirby\Cms\User $user
     * @param string $name
     * @return \Kirby\Cms\User
     * @throws KirbyRetrievalException
     */
    public function changeUserName(\Kirby\Cms\User $user, string $name): \Kirby\Cms\User
    {
        try {
            return $this->kirby->impersonate('kirby', function () use ($user, $name) {
                return $user->changeName($name);
            });
        } catch (Throwable $e) {
            throw new KirbyRetrievalException($e->getMessage());
        }
    }

    /**
     * @param \Kirby\Cms\User $user
     * @param string $role
     * @return \Kirby\Cms\User
     * @throws KirbyRetrievalException
     */
    public function changeUserRole(\Kirby\Cms\User $user, string $role): \Kirby\Cms\User
    {
        try {
            return $this->kirby->impersonate('kirby', function () use ($user, $role) {
                return $user->changerole($role);
            });
        } catch (Throwable $e) {
            throw new KirbyRetrievalException($e->getMessage());
        }
    }

    /**
     * @param \Kirby\Cms\User $user
     * @param string $email
     * @return \Kirby\Cms\User
     * @throws KirbyRetrievalException
     */
    public function changeUserEmail(\Kirby\Cms\User $user, string $email): \Kirby\Cms\User
    {
        try {
            return $this->kirby->impersonate('kirby', function () use ($user, $email) {
                return $user->changeEmail($email);
            });
        } catch (Throwable $e) {
            throw new KirbyRetrievalException($e->getMessage());
        }
    }

    // endregion
}
