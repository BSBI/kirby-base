<?php

declare(strict_types=1);

use BSBI\WebBase\helpers\PanelPasswordResetService;
use BSBI\WebBase\helpers\StoredPassword;
use Kirby\Cms\User;

/**
 * The stored-password field for a user blueprint (bsbi-web#869).
 *
 * Shows the password recorded for the user masked, with a Show button, and a
 * Reset button that sets a new generated password (and can email it) through
 * the `kirby-base/users/(:any)/reset-password` API route. Putting this field on
 * a user blueprint is what lets that route reset the role's accounts; see
 * {@see PanelPasswordResetService}.
 *
 * Whatever a form submits, the field saves the value already on disk (its
 * `save` closure), so the Panel, a stale copy in someone's unsaved edits, or
 * `update()` can't change it, and the text can't drift from the real
 * password. All writes go through
 * {@see \BSBI\WebBase\helpers\StoredPassword::record()}. (It can't be
 * `save: false`: a Panel save would then drop it from content.) It is shown
 * disabled to match.
 *
 * Only admins get the value, through the `stored` prop, or the Reset button;
 * anyone else sees that it is hidden.
 */
return [
    'props' => [
        /**
         * Always disabled: see above.
         */
        'disabled' => function (): bool {
            return true;
        },
    ],
    'save' => function (): ?string {
        $model = $this->model();
        return $model instanceof User ? StoredPassword::current($model, $this->name()) : null;
    },
    'computed' => [
        /**
         * The recorded password, for admins only.
         */
        'stored' => function (): string {
            if ($this->kirby()->user()?->isAdmin() !== true) {
                return '';
            }
            return (string) $this->model()->content()->get($this->name())->value();
        },
        /**
         * Whether the current user may see and reset the password.
         */
        'canReset' => function (): bool {
            return $this->kirby()->user()?->isAdmin() === true && $this->model() instanceof User;
        },
        /**
         * The user's id, for the reset route.
         */
        'userId' => function (): string {
            $model = $this->model();
            return $model instanceof User ? $model->id() : '';
        },
        /**
         * The user's email, for the confirmation wording.
         */
        'userEmail' => function (): string {
            $model = $this->model();
            return $model instanceof User ? (string) $model->email() : '';
        },
    ],
];
