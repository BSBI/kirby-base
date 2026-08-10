# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**kirby-base** (`open-foundations/kirby-base`) is a Kirby CMS v5 plugin (v2.0.0) that provides reusable base classes
, blueprints, snippets, and helpers for building websites. It is installed as a git submodule into consuming sites at 
`site/plugins/kirby-base`.

## Commands

- **Install dependencies:** `composer install`
- **Update dependencies:** `composer update`
- **Run tests:** `vendor/bin/phpunit` (all tests) or `vendor/bin/phpunit tests/Unit/models/UserTest.php` (single file)
- **Lint (PHP CodeSniffer):** `vendor/bin/phpcs` — enforces PSR-12 + Slevomat standards on
  `classes/`, `sections/`, `snippets/` and `templates/`. Note there is a large backlog of
  pre-existing formatting violations (~3,000, almost all auto-fixable whitespace); the ruleset
  previously pointed at a non-existent `site/` directory, so it had never actually run here.
  Treat *new* violations in code you touch as the bar, rather than expecting a clean run.
- **Fix lint issues:** `vendor/bin/phpcbf`
- **Static analysis (PHPStan):** `vendor/bin/phpstan analyse --memory-limit=1G` (level 9, config in `phpstan.neon`). The
  `--memory-limit=1G` flag is required — the default 128M limit is too low for this codebase and PHPStan will crash with
  an out-of-memory fatal without it.

There is no JavaScript build process or CSS preprocessor configured in this project.  They would be supplied by the web 
application using the plugin.

## Architecture

### Plugin Registration

`index.php` registers the plugin via `Kirby::plugin()`, pulling in configuration from separate files:
- `blueprints.php` — blueprint registration
- `snippets.php` — snippet registration
- `hooks.php` — lifecycle hooks
- `routes.php` — custom routes (sitemap.xml, robots.txt, etc.)

### PHP Classes (namespace: `BSBI\WebBase\`)

PSR-4 autoloaded from `classes/`. The class hierarchy is:

- **`BaseModel`** — foundation for all models, uses `ErrorHandling` and `OptionsHandling` traits
- **`BaseWebPage`** extends `BaseModel` — core page model with properties for menus, SEO, permissions, etc.
- **`BaseList`** / **`BaseFilter`** — collection and filtering base classes with pagination support
- **`KirbyBaseHelper`** (`classes/helpers/`) — large helper class (~6k lines) that bridges Kirby CMS data to 
model objects. Consuming sites extend this and implement `getBasicPage`/`setBasicPage`.
- **`SearchIndexHelper`** — SQLite FTS5 full-text search implementation
- **13 traits** in `classes/traits/` provide mixins for  `FormProperties`, 
`ImageHandling`, `LoginProperties`, etc.

### Extension Pattern

Consuming sites are expected to:
1. Create a `WebPage` class extending `BaseWebPage` with site-specific fields
2. Create a `KirbyHelper` class extending `KirbyBaseHelper`, implementing `getBasicPage()` and `setBasicPage()`
3. Optionally create a `CoreLinkType` enum for typed access to core navigation links

### Blueprints (`blueprints/`)

YAML files defining Kirby Panel UI structure: blocks (17 types), fields, files, layouts, pages, sections, and tabs. 
These are registered in `blueprints.php`.

### Snippets (`snippets/`)

62+ PHP template partials organized by concern: `base/` (header, footer, menu), `blocks/`, `form/`, `search/`, 
`colour-mode/`, `feedback/`, `user-status/`.

### Key Features

- **Search:** SQLite FTS5 search with configurable field weights, stop words, and optional Panel search override 
(enabled via `search.panelSearch` config option)
- **Forms:** Form builder with CSRF and Cloudflare Turnstile CAPTCHA support; submissions stored as Kirby pages
- **File archive:** Permanent URLs for downloadable files via `file_link` controller/template
- **Authentication:** Role-based access control and per-page password protection via `permissions` tab blueprint
- **Image handling:** Responsive images with srcset generation, WebP conversion, and image bank support

## Making changes

**Always pull the latest changes before starting work.** kirby-base is updated from several
consuming projects, so `origin` is frequently ahead of your local checkout — start every task with:

```
git pull --rebase origin main
```

Rebasing (rather than merging) keeps your new commits on top of the latest remote history and avoids
noisy merge commits. If a push is later rejected with "fetch first", it means new commits landed on
`origin` while you worked — `git pull --rebase origin main` again, then push.

When adding any new blueprint, snippet, or template file, you MUST also register it explicitly in the corresponding registration file — Kirby does not auto-discover files in a plugin:

- New blueprint (`.yml`) → add an entry to `blueprints.php`
- New snippet (`.php`) → add an entry to `snippets.php`
- New template (`.php`) → add an entry to the `templates` array in `index.php`

## Coding Standards

- PHP 8.3+ required (`declare(strict_types=1)` everywhere)
- PSR-12 code style with Slevomat enhancements: full type hints on parameters, returns, and properties
- PascalCase classes, camelCase methods/properties, snake_case for Kirby template/blueprint names
- PHPDoc comments for all public methods/constructors

## Testing (test-first)

Write the test first: **red → green → refactor**. Cover the happy path and the awkward
edges (empty/null relations, camelCase vs snake_case keys, draft/unlisted exclusions).

- **Run:** `vendor/bin/phpunit` (whole suite) or `vendor/bin/phpunit --filter SomeTest`
  (fast inner loop). In a consuming site, `bin/test.sh` runs both that site's suite and
  this one.
- **Make Kirby logic testable by construction — don't grow `KirbyBaseHelper`.** Its
  constructor reaches for the global `kirby()`/`site()`/`page()`, so it can't be
  instantiated in a unit test. When a change wants branching logic there, extract a
  small `final readonly` service that takes its Kirby collaborators via the constructor
  (like `KirbyFieldReader`, `ImageService`, `NavigationService`) and test the service.
  Split services by responsibility, not by method.
- **Shared test support lives in `classes/Testing/` (`BSBI\WebBase\Testing`).**
  `KirbyTestEnvironment::boot()` returns a minimal in-memory Kirby App;
  `KirbyContentBuilder` fabricates pages/structures/blocks. These are deliberately
  PHPUnit-free so they ship in the plugin autoload — test cases *compose* them, they
  don't extend them. Note: `kirby()` auto-boots an App when none exists, registering
  global error/exception handlers that PHPUnit 12 reports as risky; boot once up front
  (e.g. in `setUpBeforeClass`) to keep that out of the per-test window.
- **Hold the line.** New behaviour ships with a test written first. Don't retro-fit
  tests onto the global-state body of `KirbyBaseHelper`; extract-and-test when you touch
  it. Coverage is a diagnostic, not a target.

## Version Management

**The git tag is the only source of truth for the version.** `composer.json` deliberately has no
`version` field: Packagist infers the version from the tag, and Kirby reads it back from Composer's
`installed.php` (also tag-derived) — so nothing needs the field, and keeping one in sync by hand is
a known footgun. Do not add it back.

To release a new version, tag and push:

```
git tag 3.20.0
git push origin main --tags
```

Tags are bare version numbers, no `v` prefix (older `v1.x` tags predate that convention).

Why the field was removed: Packagist silently **drops** any tag whose `version` field disagrees with
the tag name — no error, the release just never appears. That happened three times in this repo
(`v1.0.2`, `v1.0.4`, `v1.0.6` are all tagged but absent from Packagist). Composer's own docs
recommend omitting it: *"Specifying the version yourself will most likely end up creating problems
at some point due to human error."*

It also kept `composer.lock` permanently "out of date" — `version` feeds Composer's content-hash, so
every release invalidated it. If you ever do change a dependency-relevant field in `composer.json`,
run `composer update --lock` to refresh the hash without touching resolved versions.

## Agent usage

We want to use agents for basic tasks, because as a charity we need to minimise token usage.  Use simpler models for simpler tasks.

Use the agents at /Users/jamesdrever/Websites/bsbi-web/.claude/agents

Before spawning a subagent, briefly state which agent you're using and why.
After it returns, summarise what it found in one sentence.
Use agents for any task where it is possible to do so.  For example:

- syntax-checker after editing PHP files
- test-runner after adding/running tests
- code-reviewer over `git diff main...<branch>` at the end of the branch work, **before the PR
  is opened** — that is the same diff GitHub computes for the PR, and it is available earlier.
  PRs here are created and merged in one sitting, so a review scheduled after the PR runs
  against something already on `main`.
- security-reviewer whenever code-reviewer ends with "Security escalation: recommended" (it must always end with a Security escalation verdict), or when the change obviously touches auth/sessions, request/cookie/file handling, SQL/query construction, payments, or form endpoints
- accessibility-reviewer per branch, whenever it touches snippets, templates, or front-end
  JS/CSS (see Accessibility above)
- **Re-run all of them after any later change to the branch** — not only after fixing review
  findings. Feedback from localhost or staging is new commits the reviews have not seen.
  Without a re-run, what gets merged is not what was reviewed.
