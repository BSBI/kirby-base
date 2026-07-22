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
- **Lint (PHP CodeSniffer):** `vendor/bin/phpcs` — enforces PSR-12 + Slevomat standards on the `site/` directory
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
Be aware that kirby-base may be updated from other projects.  Always best to pull the latest version of the plugin 
before making changes.

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

To release a new version: update `version` in `composer.json`, commit, tag with the version number, and push with tags.
