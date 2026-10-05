<?php

declare(strict_types=1);

namespace BSBI\WebBase\forms;

use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Exception\PermissionException;
use Kirby\Panel\Panel;

/**
 * The "Form library" side-menu entry in the panel, opt-in via the
 * `forms.libraryPanel` option.
 *
 * The library is an ordinary unlisted page (slug `form-library`) holding the
 * reusable form sections; the menu entry is a shortcut to it. Opening the entry
 * creates the page if it does not exist yet, so a site needs no manual setup.
 * Only the roles in `forms.libraryRoles` (default admin and editor) see the
 * entry or may open it.
 */
final class FormLibraryPanel
{
    /** Slug of the library page; the section pickers' queries look for it. */
    public const SLUG = 'form-library';

    /** Template of the library page. */
    public const TEMPLATE = 'form_library';

    /** Roles that see the entry when `forms.libraryRoles` is not set. */
    public const DEFAULT_ROLES = ['admin', 'editor'];

    /**
     * Returns the panel area definition.
     *
     * @param App $kirby
     * @return array<string, mixed>
     */
    public static function area(App $kirby): array
    {
        // The panel runs a view action with Closure::call($route), which rejects
        // a static closure and moves its class scope to Route, so `self::` and
        // private calls would fail there. All three closures follow the same
        // rule, in case Kirby starts binding the others too.
        return [
            'label'   => 'Form library',
            'icon'    => 'layers',
            'link'    => FormLibraryPanel::SLUG,
            'menu'    => fn(): bool => FormLibraryPanel::isAllowed($kirby->user()?->role()->id(), FormLibraryPanel::roles($kirby)),
            'current' => fn(): bool => FormLibraryPanel::isCurrentPath(
                $kirby->request()->path()->toString(),
                FormLibraryPanel::panelSlug($kirby)
            ),
            'views'   => [
                [
                    'pattern' => FormLibraryPanel::SLUG,
                    'action'  => function () use ($kirby): void {
                        FormLibraryPanel::open($kirby);
                    },
                ],
            ],
        ];
    }

    /**
     * Opens the library: creates it if missing, then redirects to its page view.
     *
     * @param App $kirby
     * @throws PermissionException if the current user's role may not use the library
     */
    public static function open(App $kirby): void
    {
        if (!self::isAllowed($kirby->user()?->role()->id(), self::roles($kirby))) {
            throw new PermissionException('You are not allowed to open the form library.');
        }

        $library = self::ensureLibrary($kirby);
        Panel::go($library->panel()->url(true));
    }

    /**
     * Returns true if a user with the given role may see and open the library.
     *
     * @param string|null $role  The user's role id, or null when nobody is logged in
     * @param string[]    $roles The roles allowed
     */
    public static function isAllowed(?string $role, array $roles): bool
    {
        return $role !== null && in_array($role, $roles, true);
    }

    /**
     * Returns true if the request path is the library page or a page inside it.
     *
     * @param string $path      Request path, e.g. 'panel/pages/form-library+contact'
     * @param string $panelSlug The panel's URL slug
     */
    public static function isCurrentPath(string $path, string $panelSlug): bool
    {
        $libraryPath = trim($panelSlug, '/') . '/pages/' . self::SLUG;
        $path = trim($path, '/');

        return $path === $libraryPath || str_starts_with($path, $libraryPath . '+');
    }

    /**
     * Returns the library page, creating it (unlisted) if it does not exist.
     * Creation runs as the Kirby superuser: callers check the role first.
     *
     * @param App $kirby
     */
    public static function ensureLibrary(App $kirby): Page
    {
        $existing = $kirby->site()->findPageOrDraft(self::SLUG);
        if ($existing instanceof Page) {
            return $existing;
        }

        $library = $kirby->impersonate('kirby', static function () use ($kirby): Page {
            // Created unlisted directly: the blueprint forbids changing status.
            return $kirby->site()->createChild([
                'slug'     => self::SLUG,
                'template' => self::TEMPLATE,
                'draft'    => false,
                'content'  => ['title' => 'Form library'],
            ]);
        });
        if (!$library instanceof Page) {
            throw new \LogicException('The form library page could not be created.');
        }
        return $library;
    }

    /**
     * Returns the panel's URL slug.
     *
     * Public because the area closures call it (see area()).
     *
     * @param App $kirby
     */
    public static function panelSlug(App $kirby): string
    {
        $slug = $kirby->option('panel.slug', 'panel');
        return is_string($slug) && $slug !== '' ? $slug : 'panel';
    }

    /**
     * Returns the roles allowed to use the library.
     *
     * Public because the area closures call it (see area()).
     *
     * @param App $kirby
     * @return string[]
     */
    public static function roles(App $kirby): array
    {
        $roles = $kirby->option('forms.libraryRoles', self::DEFAULT_ROLES);
        return is_array($roles) ? array_values(array_filter($roles, 'is_string')) : self::DEFAULT_ROLES;
    }
}
