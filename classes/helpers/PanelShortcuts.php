<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Cms\User;
use Kirby\Content\Field;
use Kirby\Data\Yaml;
use Kirby\Exception\InvalidArgumentException;
use Kirby\Exception\PermissionException;
use Kirby\Toolkit\I18n;
use Kirby\Uuid\Uuid;

/**
 * A Panel user's own shortcuts to the pages they use most (kirby-base#75):
 * added with the "Add to my shortcuts" view button on a page, listed by the
 * `myshortcuts` dashboard section.
 *
 * The list is kept in a `panelShortcuts` field on the user's own content, as
 * YAML: `[{uuid, id, tab}]`, in the user's order. No user blueprint has to
 * declare the field: Kirby keeps fields a blueprint doesn't define, both on
 * update() and when the Panel saves the user's form.
 *
 * Writes go through impersonate('kirby') because changing one's own content
 * needs the role's `user.update` permission, which sites may deny (editors on
 * bsbi-web). Callers pass only the logged-in user (see the API routes), so
 * nobody can change anyone else's list.
 *
 * Pages are found by stored path, so a dashboard load doesn't scan the site
 * index (sites with 10,000+ pages). Only a page that has moved or been
 * deleted is looked up by UUID (cache, then index), and the list is then
 * corrected (new path stored, or entry dropped), so that happens once.
 * A page the user may not access is hidden but kept, so it returns if their
 * access does.
 */
final readonly class PanelShortcuts
{
    public const string FIELD = 'panelShortcuts';
    public const int MAX = 50;

    public function __construct(private App $kirby)
    {
    }

    /**
     * The user's shortcuts as stored, invalid entries dropped.
     *
     * @return list<array{uuid: string, id: string, tab: string}>
     */
    public function stored(User $user): array
    {
        $user = $this->latest($user);
        $field = $user->content()->get(self::FIELD);
        $value = $field instanceof Field ? $field->value() : null;
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        try {
            $data = Yaml::decode($value);
        } catch (\Throwable) {
            return [];
        }

        $items = [];
        foreach ($data as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $uuid = $entry['uuid'] ?? null;
            if (!is_string($uuid) || !self::isPageUuid($uuid)) {
                continue;
            }
            $id = $entry['id'] ?? '';
            $tab = $entry['tab'] ?? '';
            $items[] = [
                'uuid' => $uuid,
                'id'   => is_string($id) ? $id : '',
                'tab'  => is_string($tab) ? self::cleanTab($tab) : '',
            ];
        }
        return $items;
    }

    /**
     * The user's shortcuts to show: pages that still exist and that the
     * logged-in user may access, with the title and Panel link read live.
     *
     * @return list<array{uuid: string, title: string, url: string, icon: string}>
     */
    public function list(User $user): array
    {
        $stored = $this->stored($user);
        $kept = [];
        $list = [];
        foreach ($stored as $item) {
            $page = $this->find($item);
            if ($page === null) {
                continue;
            }
            $item['id'] = $page->id();
            $kept[] = $item;
            if (!$page->isAccessible()) {
                continue;
            }
            $url = $page->panel()->url(true);
            if ($item['tab'] !== '') {
                $url .= '?tab=' . rawurlencode($item['tab']);
            }
            $icon = $page->blueprint()->toArray()['icon'] ?? null;
            $list[] = [
                'uuid'  => $item['uuid'],
                'title' => self::title($page),
                'url'   => $url,
                'icon'  => is_string($icon) && $icon !== '' ? $icon : 'page',
            ];
        }
        // Moved pages get their new path and deleted ones are dropped, so
        // each costs at most one index lookup, ever.
        if ($kept !== $stored) {
            $this->save($user, $kept);
        }
        return $list;
    }

    public function has(User $user, Page $page): bool
    {
        $uuid = $page->uuid()->toString();
        foreach ($this->stored($user) as $item) {
            if ($item['uuid'] === $uuid) {
                return true;
            }
        }
        return false;
    }

    /**
     * Adds the page, or updates the tab it opens on when already saved, so a
     * page appears only once.
     *
     * @throws InvalidArgumentException when the list is full
     */
    public function add(User $user, Page $page, string $tab = ''): void
    {
        $uuid = $page->uuid()->toString();
        $items = $this->stored($user);
        $entry = ['uuid' => $uuid, 'id' => $page->id(), 'tab' => self::cleanTab($tab)];

        foreach ($items as $index => $item) {
            if ($item['uuid'] === $uuid) {
                $items[$index] = $entry;
                $this->save($user, $items);
                return;
            }
        }
        if (count($items) >= self::MAX) {
            throw new InvalidArgumentException(message: str_replace(
                '{max}',
                (string) self::MAX,
                self::text('full', 'You already have {max} shortcuts. Remove one to add another.')
            ));
        }
        $items[] = $entry;
        $this->save($user, $items);
    }

    public function remove(User $user, string $uuid): void
    {
        $items = $this->stored($user);
        $kept = array_values(array_filter($items, static fn(array $item): bool => $item['uuid'] !== $uuid));
        if (count($kept) !== count($items)) {
            $this->save($user, $kept);
        }
    }

    /**
     * Moves one shortcut to a new position (0-based) in the stored list.
     * Positions past either end are clamped.
     */
    public function move(User $user, string $uuid, int $to): void
    {
        $items = $this->stored($user);
        $from = null;
        foreach ($items as $index => $item) {
            if ($item['uuid'] === $uuid) {
                $from = $index;
                break;
            }
        }
        if ($from === null) {
            return;
        }
        $to = max(0, min($to, count($items) - 1));
        if ($to === $from) {
            return;
        }
        $moved = array_splice($items, $from, 1);
        array_splice($items, $to, 0, $moved);
        $this->save($user, $items);
    }

    /**
     * Moves one shortcut up or down past the next one the user can see, so
     * hidden entries (deleted or inaccessible pages) don't swallow a move.
     *
     * @param -1|1 $direction
     */
    public function step(User $user, string $uuid, int $direction): void
    {
        $visible = array_column($this->list($user), 'uuid');
        $position = array_search($uuid, $visible, true);
        if ($position === false) {
            return;
        }
        $neighbour = $visible[$position + $direction] ?? null;
        if ($neighbour === null) {
            return;
        }
        $storedUuids = array_column($this->stored($user), 'uuid');
        $target = array_search($neighbour, $storedUuids, true);
        if ($target !== false) {
            $this->move($user, $uuid, $target);
        }
    }

    /**
     * The logged-in user, for the API routes: they never act on anyone else.
     *
     * @throws PermissionException when nobody is logged in
     */
    public static function currentUser(App $kirby): User
    {
        $user = $kirby->user();
        if (!$user instanceof User) {
            throw new PermissionException(message: 'You must be logged in.');
        }
        if (!self::isAllowed($kirby, $user)) {
            throw new PermissionException(message: 'Shortcuts are not available for your role.');
        }
        return $user;
    }

    /**
     * Whether the user's role has shortcuts: the `panelShortcuts.roles`
     * option lists them, and unset means every role with Panel access. A site
     * leaves out roles that never see the dashboard (bsbi-web's vice-county
     * recorders), as their shortcuts would have nowhere to show.
     */
    public static function isAllowed(App $kirby, User $user): bool
    {
        $roles = $kirby->option('panelShortcuts.roles');
        if (!is_array($roles)) {
            return true;
        }
        return in_array($user->role()->id(), $roles, true);
    }

    /**
     * The `myshortcut` page view button (registered on Kirby's site area):
     * "Add to my shortcuts", or "Remove from my shortcuts" when the page is
     * already saved, worked out here so the first paint is right.
     *
     * @return array<string, mixed>|null
     */
    public static function viewButton(Page $page): ?array
    {
        $kirby = $page->kirby();
        $user = $kirby->user();
        $uuid = $page->uuid()->toString();
        if (!$user instanceof User || !self::isAllowed($kirby, $user)) {
            return null;
        }
        return [
            'component'  => 'k-myshortcut-view-button',
            'saved'      => (new self($kirby))->has($user, $page),
            'pageId'     => $page->id(),
            'uuid'       => $uuid,
            'pageTitle'  => self::title($page),
            'addText'    => self::text('add', 'Add to my shortcuts'),
            'removeText' => self::text('remove', 'Remove from my shortcuts'),
        ];
    }

    /**
     * A kirby-base.shortcuts.* Panel string in the user's Panel language.
     */
    public static function text(string $key, string $fallback): string
    {
        $text = I18n::translate('kirby-base.shortcuts.' . $key, $fallback);
        return is_string($text) ? $text : $fallback;
    }

    private static function title(Page $page): string
    {
        $title = $page->title()->value();
        return is_string($title) && $title !== '' ? $title : $page->slug();
    }

    public static function isPageUuid(string $uuid): bool
    {
        return preg_match('#^page://[A-Za-z0-9_-]+$#', $uuid) === 1;
    }

    /**
     * @param array{uuid: string, id: string, tab: string} $item
     */
    private function find(array $item): ?Page
    {
        if ($item['id'] !== '') {
            $page = $this->kirby->site()->findPageOrDraft($item['id']);
            if ($page instanceof Page && $page->uuid()->toString() === $item['uuid']) {
                return $page;
            }
        }
        // Moved: Kirby drops the cache entry when a slug changes, so after
        // the cache, one index lookup (which re-caches the page). list()
        // then stores the new path, or drops the entry if the page is gone.
        try {
            $model = Uuid::for($item['uuid'])?->model();
        } catch (\Throwable) {
            // content.uuid.index switched off, or the lookup failed
            $model = null;
        }
        return $model instanceof Page ? $model : null;
    }

    /**
     * @param list<array{uuid: string, id: string, tab: string}> $items
     */
    private function save(User $user, array $items): void
    {
        $value = $items === [] ? '' : Yaml::encode($items);
        $user = $this->latest($user);
        $this->kirby->impersonate('kirby', static function () use ($user, $value): void {
            $user->update([self::FIELD => $value]);
        });
    }

    /**
     * The user as now stored. Kirby's update() returns a new object and makes
     * the old one immutable, so one held from before a change (as in an API
     * route making several) has the old list and can't be saved again.
     */
    private function latest(User $user): User
    {
        return $this->kirby->user($user->id()) ?? $user;
    }

    private static function cleanTab(string $tab): string
    {
        return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $tab) === 1 ? $tab : '';
    }
}
