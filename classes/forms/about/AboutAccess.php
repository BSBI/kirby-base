<?php

declare(strict_types=1);

namespace BSBI\WebBase\forms\about;

use BSBI\WebBase\forms\FormLibraryPanel;
use Closure;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Cms\User;

/**
 * Who may see the feedback about a page (its Feedback tab): the Forms roles
 * (`forms.libraryRoles`) see every page's; anyone else only where the site's
 * rule, the `forms.aboutViewer` option (fn(User, Page $subject): bool), says
 * so, e.g. a local editor for their own events. Without a rule, only the
 * Forms roles.
 *
 * The check is made on the server for each request, so it holds even where
 * a role's panel view, not its permissions, is what keeps it to its own pages.
 */
final readonly class AboutAccess
{
    public function __construct(private App $kirby)
    {
    }

    /**
     * Returns true if the user is in one of the Forms roles.
     */
    public function isFormsRole(?User $user): bool
    {
        return FormLibraryPanel::isAllowed($user?->role()->id(), FormLibraryPanel::roles($this->kirby));
    }

    /**
     * Returns true if the user may see the feedback about the subject.
     */
    public function canSee(?User $user, Page $subject): bool
    {
        if ($user === null) {
            return false;
        }
        if ($this->isFormsRole($user)) {
            return true;
        }
        $rule = $this->kirby->option('forms.aboutViewer');
        return $rule instanceof Closure && $rule($user, $subject) === true;
    }
}
