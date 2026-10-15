# Search

kirby-base keeps a SQLite FTS5 search index, by default at `logs/search/search.sqlite`
(the `search.databasePath` option). Page hooks keep it current on every save. The
Panel's Indexes page rebuilds it from scratch.

## Which pages: the `allPages` collection

The search rows come from a collection the site provides, named `allPages`:

```php
// site/collections/allPages.php
return fn ($site) => $site->index()->listed()->filterBy('intendedTemplate', 'in', ['default', 'article']);
```

Within it, a page is indexed when it is listed or unlisted, and not excluded by
`search.excludedTemplates` or a configured path prefix.

**Without an `allPages` collection, the rebuild indexes every listed and unlisted page
on the site** (`site()->index()`). That is fine for a small site. The rebuild logs that
it did, in `logs/search-index.log`, and the Panel's message says so. **A large site
should provide the collection.** `site()->index()` reads every page on the site, and the
fallback indexes pages, such as form submissions, that nobody searches for.

## Panel search: `search.panelSearch`

With `search.panelSearch` on, the Panel's own page search uses the index too. Its titles
come from a second table, `all_pages`, which holds every page on the site, drafts
included. A rebuild fills it from `site()->index(true)`.

With the option off, the default, nothing reads that table, so a rebuild leaves it alone.
That walk reads every page, draft and version on the site, and costs a large site
minutes.

## Rebuilding

From the Panel's Indexes page (`contentIndex.showIndexStatsPanel`), or a POST to the
API's `indexes/rebuild/search` with the CSRF token. The old `GET /search-rebuild`
answers 405 and rebuilds nothing; see
[content-index.md](content-index.md#rebuild-indexes).

The search rebuild still empties and refills its tables in one transaction. Saves that
update the search index wait for it to finish. Rebuilding search in place, as content
indexes are, is a follow-up.
