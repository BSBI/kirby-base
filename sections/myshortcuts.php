<?php

declare(strict_types=1);

/**
 * myshortcuts panel section (kirby-base#75).
 *
 * The logged-in user's own shortcuts to Panel pages, added with the
 * "Add to my shortcuts" view button (`myshortcut`) on a page. Rows can be
 * removed and moved up or down; changes go through the kirby-base/shortcuts
 * API routes, which only ever touch the logged-in user's list.
 *
 * Blueprint usage (typically on the site/dashboard blueprint):
 * <code>
 * myShortcuts:
 *   type: myshortcuts
 * </code>
 *
 * See documentation/panel-shortcuts.md.
 */

use BSBI\WebBase\helpers\PanelShortcuts;
use Kirby\Cms\User;

return [
    'props' => [
        /**
         * Section headline.
         */
        'headline' => function (?string $headline = null): string {
            return $headline ?? PanelShortcuts::text('headline', 'My shortcuts');
        },
    ],
    'computed' => [
        /**
         * The logged-in user's shortcuts.
         *
         * @return list<array{uuid: string, title: string, url: string, icon: string}>
         */
        'shortcuts' => function (): array {
            $kirby = kirby();
            $user = $kirby->user();
            return $user instanceof User && PanelShortcuts::isAllowed($kirby, $user)
                ? (new PanelShortcuts($kirby))->list($user)
                : [];
        },
    ],
];
