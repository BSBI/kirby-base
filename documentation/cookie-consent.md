# Cookie Consent

Kirby-base provides a cookie consent system with a dismissable banner, consent-gated content blocks, and a small JS API for checking status.

## How it works

`cookie-consent.js` is loaded as an external file at the end of `<body>`. On each page it:

1. Reads the `cookieConsent` cookie (`accepted` or `rejected`; any other value counts as no choice)
2. Hides the banner if a choice has already been recorded
3. Shows placeholders and hides consent-gated content blocks if consent has not been accepted
4. Hides the "Reject" button in placeholders if the user has already rejected
5. Wires up Accept/Reject click handlers on all `[data-consent-accept]` and `[data-consent-reject]` buttons
6. Exposes `window.cookieConsent` for external use

## Setup in a consuming site

Unlike colour mode, cookie consent requires two steps in the consuming site:

**1. Load the JS.** In your `KirbyHelper::setBasicPage()` call `addScript`:

```php
$currentPage->addScript('cookie-consent', self::ASSETS_PATH);
```

`ASSETS_PATH` should resolve to `/media/plugins/open-foundations/kirby-base/`.

**2. Include the banner snippet.** Kirby-base ships a generic `cookie-consent/banner` snippet with all data attributes pre-wired. Include it in your header:

```php
<?php snippet('cookie-consent/banner', [
    'description'     => 'We use cookies to keep you logged in and remember your preferences.',
    'privacyPolicyUrl' => '/privacy',
]) ?>
```

Parameters:

| Parameter | Default | Description |
|-----------|---------|-------------|
| `$description` | `'This site uses cookies to improve your experience.'` | Banner description text |
| `$privacyPolicyUrl` | — | Optional link to a privacy policy page |

If you need site-specific markup, write your own banner using these data attributes:

| Element | Attribute | Purpose |
|---------|-----------|---------|
| Banner wrapper | `data-cookie-consent-banner` | Hidden by JS once a choice is recorded |
| Accept button | `data-consent-accept` | Sets status to `accepted` |
| Reject button | `data-consent-reject` | Sets status to `rejected` |

## Gating content behind consent

Wrap content in a `[data-requires-consent]` container with a placeholder and the real content as siblings:

```html
<div data-requires-consent>
  <div data-consent-placeholder>
    <!-- shown until consent is accepted -->
    <p>This map requires your consent to cookies.</p>
    <button type="button" data-consent-accept>Accept cookies</button>
    <button type="button" data-consent-reject data-consent-reject-btn>Reject</button>
  </div>
  <div data-consent-content>
    <!-- hidden until consent is accepted -->
    <iframe src="…"></iframe>
  </div>
</div>
```

Kirby-base ships a `block-consent` snippet that renders the placeholder half:

```php
<?php snippet('block-consent', ['contentType' => 'map', 'purpose' => 'We use Google Maps to display location information.']) ?>
```

Parameters:

| Parameter | Default | Description |
|-----------|---------|-------------|
| `$contentType` | `'content'` | Shown in "This `X` requires your consent" |
| `$purpose` | — | Optional additional explanation |

## JavaScript API

After the script loads, `window.cookieConsent` is available:

```js
window.cookieConsent.hasConsent()   // true if accepted
window.cookieConsent.hasRejected()  // true if rejected
window.cookieConsent.getStatus()    // 'accepted', 'rejected', or null
window.cookieConsent.setStatus('accepted') // programmatically accept
```

## Cookie

| Property | Value |
|----------|-------|
| Name | `cookieConsent` |
| Values | `accepted`, `rejected` |
| Expiry | 365 days |
| Path | `/` |
| SameSite | `Lax` |

The old `cookieConsentGiven` cookie (values `true`/`yes`/`1`/`no`) is no longer read (bsbi-web #445): a visitor who only has it is asked again.

---

## Comparison with colour mode

Colour mode is entirely self-contained in kirby-base — every consuming site will want it, so zero-setup makes sense. Cookie consent intentionally requires more wiring in the consuming site because not all sites will need it (if a site doesn't use cookies requiring explicit consent, there is nothing to set up).

Forcing the banner and JS into every page via `base/header` would be the wrong default.

The `cookie-consent/banner` snippet provides a ready-made starting point so that when a consuming site does need a banner, the data attributes are pre-wired and only the wording needs overriding.
