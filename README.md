# kirby-base

Shared Kirby plugin used by several BSBI sites. Released by git tag; consumers pin an exact
version in their own `composer.json`.

## Consuming sites must provide these dependencies themselves

This plugin declares runtime dependencies in its own `composer.json`:

| Package | Why |
|---|---|
| `setasign/fpdi` | reads an existing PDF so a certificate can be overlaid onto the designer's artwork |
| `tecnickcom/tcpdf` | writes the PDF; UTF-8 native, so names like *Siân* or *Ó Briain* survive |

**How those reach the site depends on how the plugin is being used, and the two differ:**

- **Installed by Composer** (servers, and any site not developing the plugin) — Composer flattens
  our requirements into the *site's* `vendor/`, and they autoload with everything else. Nothing to
  do.
- **Checked out as a git submodule** (local development) — our `composer.json` is never seen by the
  site's Composer, so those packages are absent from the site's `vendor/`. Anything touching
  certificates then fails with:

  ```
  Class "setasign\Fpdi\PdfReader\PdfReader" not found
  ```

  even though the same code works on a server.

### What a consuming site should do

Declare them in the site's own `composer.json` and install with a **targeted** update:

```json
"require": {
    "setasign/fpdi": "^2.6",
    "tecnickcom/tcpdf": "^6.11"
}
```

```bash
composer update setasign/fpdi tecnickcom/tcpdf
```

Targeted, never a bare `composer update`: this plugin is installed to
`site/plugins/kirby-base`, the same path as the submodule, so an untargeted run deletes the
submodule working tree.

This duplicates our requirement into each consuming site, which is not lovely. It is deliberate:
the alternative is for this plugin to `require` its own `vendor/autoload.php`, and it must not.
Our `require-dev` includes `getkirby/cms`, so that directory holds a **second Kirby**, and
Composer's generated autoloader registers with `prepend = true` — loading it would put the wrong
Kirby ahead of the site's for every class not yet loaded. Two declared lines beat that.

## Working on this plugin

See [CLAUDE.md](CLAUDE.md) for the development workflow, testing and version management.
