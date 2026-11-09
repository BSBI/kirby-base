# Custom Fields

KirbyBase includes specialized field types that extend Kirby's built-in fields to address specific use cases.

## usernamesearch

A drop-in replacement for Kirby's built-in `users` field that uses AND-of-words matching on full names instead of Kirby's default OR-of-words search across name, email, and role.

### Problem

On sites with large user bases (1,000+), Kirby's default user picker uses the global search component, which matches *any* typed word across a user's name, email, and role fields. For example, searching "Janet Simkin" returns ~152 results (everyone called Janet OR anyone called Simkin) instead of narrowing to the specific person.

### Solution

The `usernamesearch` field type filters the user list using AND-of-words matching on the user's full name only. Every word in the search query must appear somewhere in the name (case-insensitive, substring-based). Tokens shorter than 2 characters are dropped as noise.

### Usage

In any blueprint, use `type: usernamesearch` instead of `type: users`:

```yaml
postedBy:
  type: usernamesearch
  label: Posted by
  max: 1
  readonly: false
```

It is a drop-in replacement — the stored value format, props (`readonly`, `min`, `max`, etc.), and panel UI are identical to the core `users` field.

### Implementation

The field is implemented in two parts:

1. **PHP backend** (`classes/cms/UserNamePicker.php` and `classes/helpers/UserNameSearch.php`):
   - `UserNameSearch` — pure, unit-tested helper with two static methods:
     - `words(string $query): array` — splits a query into lowercased tokens, dropping anything shorter than 2 characters
     - `nameMatches(string $name, array $words): bool` — returns true only if every word appears in the name
   - `UserNamePicker` — extends Kirby's `UserPicker` and overrides only the `search()` method to use `UserNameSearch` logic. All other picker behaviour (querying, listable filtering, sorting, pagination, formatting) is inherited unchanged.

2. **Vue panel component** (`index.js`):
   - Registers the panel field component `usernamesearch` which extends the core `k-users-field`, so it renders identically to the built-in field.

> [!NOTE]
> A Kirby custom field requires **both** the PHP definition (backend) AND the panel Vue component (frontend). Without either half, the panel will show "field type X does not exist".

### Examples

**Search works with substrings and every word must match:**

| Query | Janet Simkin | Janet Wilson | Simon Simkin |
|-------|--------------|--------------|-------------|
| "janet" | ✓ | ✓ | ✗ |
| "simkin" | ✓ | ✗ | ✓ |
| "janet simkin" | ✓ | ✗ | ✗ |
| "ja sim" | ✓ | ✗ | ✗ |
| "janet wil" | ✗ | ✓ | ✗ |

**Short tokens are ignored:**

- Query "a jane" → treated as "jane" (drops "a")
- Query "j s" → returns all users (no tokens >= 2 characters)

### When to use

Use `usernamesearch` when:
- Your site has 500+ users and full-name search is a common panel task
- You want to reduce false positives from first-name-only or last-name-only matches
- Your users have short, common names that would generate many OR-based false positives

You can safely use it alongside the standard `users` field — the choice is per-field, so different page types can use different picker strategies.

## memberpassword

A user's stored, readable password, for sites that hand members their passwords (bsbi-web#869). Put it on a user blueprint:

```yaml
displayPassword:
  type: memberpassword
  label: Password
```

In the Panel it shows:

- the password **masked**, with a **Show password** toggle button (fixed label; `aria-pressed` and the icon show its state);
- a **Reset password** button. It opens a confirmation with an **Email the new password** toggle (off by default). The new generated password becomes the user's real password, is recorded in the field and is shown. The outcome goes to a persistent `aria-live` status line.

Only **admins** see the value or the buttons; anyone else sees "Only admins can see this". That hides it in the Panel, but the user's content is still readable through Kirby's API by any role with access to users. A site that wants the passwords admin-only must also keep other roles out of the Users area (`access.users: false`, `users: false`).

### Opt-in and who can be reset

The reset route (`POST /api/kirby-base/users/(:any)/reset-password`, body `{ "notify": bool }`) refuses unless:

- the current user is an admin; and
- the target user's blueprint has a `memberpassword` field (that's how a site opts a role in), and the target isn't an admin.

Every reset is logged to `logs/password-resets.log`: who reset whom, and whether the email went. The password is never logged.

### The stored value can't drift

The field ignores whatever a form submits and saves the value already on disk. That applies to a Panel save, a stale copy in someone's unsaved changes, and `$user->update()`, so the text can't be typed over or fall out of step with the real password. (It can't be `save: false`: Kirby would then drop the value on every Panel save.)

Every write goes through `StoredPassword::record($user, $password)`. That includes the admin reset, and should include a site's own change-password and reset-password pages. It writes the stored content directly, as the system user, so it works for roles that can't update their own account. It also writes into a pending "changes" version, so publishing old unsaved edits can't bring back the old password. User update hooks don't run for it. Creating a user with the field in `content` (e.g. from a members sync) stores it as given.

### Passwords

`MemberPasswordGenerator` picks three words with `random_int` and joins them with one of `-`, `_` or `*`, e.g. `Allium-Acer-Arbutus`. The default list is 200 plant genera.

### Options

| Option | Default | |
|---|---|---|
| `memberPasswords.words` | plant genera | list of words to use instead (each at least 2 characters) |
| `memberPasswords.email.from` | `defaultEmail` | sender; no email is sent if neither is set |
| `memberPasswords.email.replyTo` | the sender | |
| `memberPasswords.email.subject` | "Your new password for <site title>" | |
| `memberPasswords.loginUrl` | `<site url>/login` | link in the email |

### The email

Templates `emails/password-reset-by-admin.html` / `.text` ship with the plugin. A site replaces them by putting files with the same names in its own `site/templates/emails/`. Data: `name`, `email`, `password`, `siteName`, `loginUrl`. Sending uses `EmailService`, so it is suppressed in local environments as usual; a failed send is reported in the Panel and doesn't undo the reset.
