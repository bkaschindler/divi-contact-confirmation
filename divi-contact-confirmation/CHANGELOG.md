# Changelog

All notable changes to this project are documented here.  
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).  
This project uses [Semantic Versioning](https://semver.org/).

---

## [1.5.2] — 2026-06-26

### Fixed
- **Legitimate users blocked with "reCAPTCHA token missing"** — the reCAPTCHA token is
  generated asynchronously; if a user submitted the form before the Promise resolved,
  `dccToken` was still empty and their submission was rejected.
  Fix: intercept the Divi submit button click via a capturing `addEventListener`. If the
  token is not yet ready, the click is cancelled, a fresh token is fetched, and the button
  is re-clicked automatically — the user sees no delay.
- Moved XHR prototype patch to `wp_head` at priority 1 (was in footer) so it runs before
  Divi scripts load and cannot be bypassed by early script caching of XHR references.
- Replaced background `setInterval` token refresh with a `Promise`-based `getToken()`
  helper reused by both the pre-warm call and the submit-button intercept.

---

## [1.5.1] — 2026-06-26

### Fixed
- **Critical:** confirmation emails were not being sent after 1.5.0 introduced a WordPress nonce
  gate that blocked all four hook layers — including legitimate browser submissions — because
  Divi does not always use jQuery AJAX, so the nonce was never injected into the request.
  Removed the nonce gate entirely; Google reCAPTCHA v3 alone is sufficient bot protection.
- **Critical:** reCAPTCHA JS was injecting the token as `g-recaptcha-response`, which overwrote
  Divi own reCAPTCHA token and caused Divi to show "You must be human to submit this form."
  The field name is now `dcc_recaptcha_token` to avoid any conflict with Divi.
- Reduced reCAPTCHA API verification timeout from 10 s to 5 s to prevent slow form submissions.
- Removed unused `dcc_nonce` injection from frontend JS.

---

## [1.5.0] — 2026-06-26

### Added
- **Google reCAPTCHA v3** — optional bot protection. When a Site Key and Secret Key are saved
  in the Security tab the plugin automatically:
  - Injects the reCAPTCHA v3 script on every frontend page
  - Intercepts Divi 4 (jQuery AJAX) and Divi 5 (Fetch API) form submissions to attach the
    reCAPTCHA token before the request is sent
  - Verifies the token server-side via `https://www.google.com/recaptcha/api/siteverify`
  - Blocks submissions whose score falls below the configurable **Minimum score** threshold
    (default 0.5; range 0.0 – 1.0)
  - Network errors during verification are silently allowed through so a Google outage never
    blocks real users
- **WordPress nonce guard** — when reCAPTCHA is configured, a one-time `dcc_submit` nonce is
  injected alongside the reCAPTCHA token. Requests that arrive without this nonce (direct AJAX
  calls from bots that never loaded the page) are dropped before any processing occurs.
- New Security-tab settings: reCAPTCHA Site Key, reCAPTCHA Secret Key, Minimum score
- New Diagnostics row: **reCAPTCHA v3 configured** — shows whether both keys are saved
- New blocked-reason labels: `recaptcha_missing`, `recaptcha_failed`
- reCAPTCHA options added to the uninstall cleanup list

### Changed
- Version bump to 1.5.0

---

## [1.4.0] — 2026-06-18

### Fixed
- **Critical:** confirmation emails were not sent on most Divi installations because
  Divi processes contact forms via AJAX and its internal `et_pb_contact_form_submit`
  action hook is not reliably accessible to third-party plugins.

### Added
- **Three-layer hook detection** in `DCC_Hooks` — first successful match triggers the
  send; a `static $sent` flag prevents duplicate emails if multiple layers fire:
  1. **`wp_mail` filter (primary)** — intercepts Divi's admin-notification call,
     reads the `Reply-To` header (set by Divi to the submitter's address), and
     pulls all field values directly from `$_POST`. Works on every Divi version.
  2. **`et_pb_contact_form_submit` action (fallback)** — Divi 4 named hook.
  3. **`divi_contact_form_submitted` action (fallback)** — Divi 5 named hook.
- **Diagnostics tab** (`Settings → Divi Confirmation → Diagnostics`):
  - **Send test email** form — send a real confirmation to any address without
    needing to submit a Divi form. Result (success / failure) shown as an
    admin notice after redirect.
  - **System information table** — shows plugin version, WordPress version,
    PHP version, active theme, whether Divi is detected and its version,
    `wp_mail()` availability, current From email, plugin enabled status,
    log table presence, and `checkdnsrr()` availability.
- German keyword additions to name-field detection (`vorname`, `nachname`).

### Changed
- Version bump to 1.4.0 in plugin header and `DCC_VERSION` constant.
- `DCC_Hooks::init()` registers four hooks instead of two.

---

## [1.3.0] — 2026-06-18

### Added
- Full internationalisation (i18n) support
- **Persian (fa_IR)** translation — 59 strings, full RTL-ready
- **German (de_DE)** translation — 59 strings
- `languages/divi-contact-confirmation.pot` master translation template
- `bin/compile-mo.py` — Python 3 script to compile `.po` → `.mo` without requiring external tools
- `load_plugin_textdomain()` call in `dcc_init()` so WordPress auto-selects the correct language based on **Settings → General → Site Language**

### Changed
- Version bump to 1.3.0

---

## [1.2.0] — 2026-06-18

### Added
- **Security tab** in the admin UI with the following options:
  - Master enable/disable toggle (disable without deactivating the plugin)
  - Per-IP rate limiting (configurable max emails per hour)
  - Blocked email domains list (comma-separated)
  - Blocked keyword list (suppresses email if any submitted field matches)
  - Optional MX record check before sending
  - Toggle to log blocked/suppressed attempts
- `dcc_should_send` filter — lets developers veto a send programmatically
- `DCC_Security` class handling all security checks in one place
- Security defaults set on activation

### Changed
- Version bump to 1.2.0 in plugin header and `DCC_VERSION` constant
- Uninstall hook now also removes security options

---

## [1.1.0] — 2026-06-17

### Added
- **Logs tab** in the admin UI — paginated table of every sent/failed email
- `DCC_Logger` class writing to a custom `wp_dcc_log` database table
- Log table created via `dbDelta()` on activation (safe to re-run on upgrade)
- "Clear all logs" button with confirmation dialog
- Error message column capturing PHPMailer error info on failure
- Log table and all options dropped cleanly on plugin uninstall
- Author info (Mohammad Babaei / adschi.com) in all file headers
- Admin page footer showing plugin version and author link

### Changed
- `DCC_Mailer::send()` now calls `DCC_Logger::write()` after every send attempt
- Version bump to 1.1.0

---

## [1.0.0] — 2026-06-17

### Added
- Initial release
- Hooks into Divi 4 (`et_pb_contact_form_submit`) and Divi 5 (`divi_contact_form_submitted`)
- Auto-detects submitter email and name from form fields
- Confirmation email sent via `wp_mail()` with configurable subject, body, from name, from email
- Dynamic placeholders: `{name}`, `{email}`, `{site_name}`, `{site_url}`, `{date}`, `{time}`, plus any form field ID
- Admin settings page under **Settings → Divi Confirmation**
- Developer filters: `dcc_confirmation_subject`, `dcc_confirmation_body`, `dcc_confirmation_headers`
- Activation hook sets sensible default options
