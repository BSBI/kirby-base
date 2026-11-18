# Personal Panel shortcuts

Each Panel user keeps their own list of shortcuts to the pages they use most
(kirby-base#75). An **Add to my shortcuts** button at the top of every page
saves it. A **My shortcuts** section, placed on the dashboard, lists them.
There, each shortcut can be moved up or down, or removed. When a page is
already saved, the button reads **Remove from my shortcuts**.

A shortcut remembers the tab it was saved from (`?tab=`), so it opens there.
Saving the same page again from another tab replaces the tab, so each page
appears only once. A user can keep up to 50 shortcuts.

## Adopting it in a site

1. **Show the button** by listing `myshortcut` in the page view buttons. A plugin
   can't add a button to Kirby's defaults, so the site lists them all in
   `config.php`. These are Kirby 5's defaults with `myshortcut` added:

   ```php
   'panel' => [
       'viewButtons' => [
           'page' => ['open', 'preview', 'myshortcut', '-', 'settings', 'languages', 'status'],
       ],
   ],
   ```

   A page blueprint that sets its own `buttons:` overrides this, so add
   `myshortcut` there too:

   ```yaml
   buttons:
     - open
     - myshortcut
     - settings
   ```

2. **Place the section** on the site blueprint, usually the dashboard:

   ```yaml
   myShortcuts:
     type: myshortcuts
     # headline: My shortcuts   (optional)
   ```

3. **Optionally limit roles.** Unset means every role with Panel access:

   ```php
   'panelShortcuts' => ['roles' => ['admin', 'editor']],
   ```

   Leave out roles that never see the section. Their shortcuts would have
   nowhere to show. bsbi-web leaves out vice-county recorders, who are sent
   to their own landing page instead of the dashboard.

No user blueprint changes are needed.

## How it works

- **Storage:** a `panelShortcuts` field on the user's own content, as YAML
  `[{uuid, id, tab}]`, in the user's order. No blueprint declares it. Kirby keeps
  fields a blueprint doesn't define, both on `update()` and when the Panel saves
  a user's form.
- **Writes** go through `PanelShortcuts` (`classes/helpers/`) and run as
  `impersonate('kirby')`. Changing one's own content needs the role's
  `user.update` permission, which sites may deny (bsbi-web's editors). The API
  routes (`kirby-base/shortcuts`, `…/add`, `…/remove`, `…/step`) act only on
  the logged-in user and never take a user from the request. Kirby's API
  rejects users without Panel access before any route runs.
- **Finding pages** is cheap on large sites. A shortcut is looked up by its
  stored path first. Only a page that has moved or been deleted falls back to
  a UUID lookup (cache, then index). The list is then corrected, with the new
  path stored or the entry dropped, so each move or deletion costs one lookup.
  Kirby drops a page's UUID cache entry when its slug changes, which is why the
  path comes first.
- **Hidden, not dropped:** a page the user may not access is left out of the
  list but kept, so it comes back if their access does.
- **Titles and icons** are read live, so renamed pages show their new title.
- **Accessibility:** the button changes its own label, and a persistent live
  region announces "… added to your shortcuts". In the section, Move up, Move
  down and Remove are real buttons named after the page. Focus stays with the
  row after a move, and goes to the next row (or the heading) after a remove.
  One persistent live region announces each change.
- **Strings** are Panel translations under `kirby-base.shortcuts.*` (`index.php`),
  so a site can translate them.

## Limits

- **Two changes at the same moment** by one user (say, in two browser tabs) can
  lose one of them: each request reads the list, changes it and saves it whole.
  Within one view, a second click waits for the first to finish.

- **Pages only:** not files, users or external links.
- **Editing a user while they change their shortcuts:** if an admin has that
  user's form open with unsaved changes, then saves, the shortcut list as it
  was when they started editing is saved back. It's rare, and the user can add
  the shortcut again.
