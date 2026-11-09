<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

use Kirby\Cms\Language;
use Kirby\Cms\User;
use Kirby\Content\ImmutableMemoryStorage;
use Kirby\Content\VersionCache;
use Kirby\Content\VersionId;

/**
 * The readable copy of a user's password kept in their `memberpassword`
 * field (bsbi-web#869), for sites that hand members their passwords.
 *
 * Every write of that copy goes through {@see self::record()}, so it stays in
 * step with the real password: when the password is reset by an admin, reset
 * by the user, or changed by the user.
 */
final class StoredPassword
{
    public const string FIELD_TYPE = 'memberpassword';

    /**
     * The name of the user's `memberpassword` field, if their blueprint has one.
     *
     * @param User $user The user
     * @return string|null The field name, as written in the blueprint
     */
    public static function fieldName(User $user): ?string
    {
        foreach ($user->blueprint()->fields() as $name => $definition) {
            if (is_array($definition) && ($definition['type'] ?? null) === self::FIELD_TYPE && is_string($name)) {
                return $name;
            }
        }
        return null;
    }

    /**
     * The stored value as it is on disk, which is what the field saves whatever
     * a form submits (see the field's `save` option).
     *
     * @param User $user The user
     * @param string $field The field name
     * @return string|null The stored value, if any
     */
    public static function current(User $user, string $field): ?string
    {
        $value = $user->version(VersionId::latest())->read(Language::ensure('default'))[strtolower($field)] ?? null;
        return is_string($value) ? $value : null;
    }

    /**
     * The latest version of a user object. Kirby models are immutable: each
     * save leaves the old object behind, pointing to its successor, and
     * writing through an old one fails ("Storage for the user is immutable").
     * Logging in runs hooks that save the user (e.g. lastLogin), so an object
     * from before a login, or before changePassword(), is often stale; and in
     * a real request `users()->find()` can return a stale one too
     * (bsbi-web#868).
     *
     * @param User $user Any version of the user
     * @return User The latest version
     */
    public static function currentAlteration(User $user): User
    {
        while (($storage = $user->storage()) instanceof ImmutableMemoryStorage
            && ($next = $storage->nextModel()) instanceof User) {
            $user = $next;
        }
        return $user;
    }

    /**
     * Records the user's new password in their `memberpassword` field. Does
     * nothing for a user whose blueprint has no such field.
     *
     * Written to the content storage directly. The field ignores anything a
     * form submits (so the Panel, or a stale copy in someone's unsaved edits,
     * can't change it), which means `update()` can't either; and this way it
     * works whatever the role may update. User update hooks don't run.
     *
     * A pending "changes" version gets the new value too: it holds a snapshot
     * of the field from when the edit began, and publishing it copies that
     * snapshot back.
     *
     * @param User $user The user whose password has just changed
     * @param string $password The new password
     * @return void
     */
    public static function record(User $user, string $password): void
    {
        $user = self::currentAlteration($user);
        $field = self::fieldName($user);
        if ($field === null) {
            return;
        }

        $language = Language::ensure('default');
        foreach ([VersionId::latest(), VersionId::changes()] as $versionId) {
            $version = $user->version($versionId);
            if (!$version->exists($language)) {
                continue;
            }
            $user->storage()->update(
                versionId: $versionId,
                language: $language,
                fields: [...($version->read($language) ?? []), strtolower($field) => $password]
            );
            VersionCache::remove($version, $language);
        }
    }
}
