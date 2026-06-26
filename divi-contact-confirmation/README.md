# Divi Contact Form — Confirmation Email

**Version:** 1.5.2  
**Author:** [Mohammad Babaei](https://adschi.com)  
**License:** GPL-2.0-or-later  
**Requires WordPress:** 6.0+  
**Tested up to:** 6.7  
**Requires PHP:** 7.4+  
**Compatible with:** Divi 4, Divi 5

---

## Description

Automatically sends a confirmation email to visitors after they submit any Divi contact form on your site. No changes to your Divi modules are required — just install, activate, and configure the email template.

---

## Features

- Works with **Divi 4** and **Divi 5** out of the box via four-layer hook detection
- Fully customisable email **subject** and **body** with dynamic placeholders
- **Google reCAPTCHA v3** — invisible bot protection with configurable score threshold
- **Security tab** — rate limiting, domain blocking, keyword filtering, MX record check, reCAPTCHA
- **Logs tab** — every sent / failed / blocked email with status and error details
- **Diagnostics tab** — send a test email and inspect system info without touching any form
- Translations included: **English**, **Persian (fa_IR)**, **German (de_DE)**
- Cleans up all data (DB table + options) on uninstall

---

## Installation

### From the WordPress dashboard (recommended)

1. Download `divi-contact-confirmation.zip`.
2. Go to **Plugins → Add New → Upload Plugin**, select the ZIP, click **Install Now**, then **Activate**.
3. Go to **Settings → Divi Confirmation** to configure.

### Via FTP / File Manager

1. Upload the `divi-contact-confirmation` folder to `/wp-content/plugins/`.
2. Activate from **Plugins → Installed Plugins**.

> **Upgrading from an earlier version?**  
> Deactivate and re-activate the plugin. `dbDelta()` will safely create or upgrade the log table without touching existing data.

---

## How it works — hook detection

Divi processes contact forms over AJAX, which makes its internal action hooks unreliable for third-party plugins. This plugin uses **three layers** — the first successful match triggers the send, and a deduplication flag prevents duplicate emails:

| Layer | Method | Description |
|---|---|---|
| 1 (primary) | `wp_mail` filter | Fires when Divi sends its admin notification. Reads the `Reply-To` header (Divi sets this to the submitter's email) and pulls all field values from `$_POST`. Works on every Divi version. |
| 2 (fallback) | `et_pb_contact_form_submit` | Divi 4 named action hook. |
| 3 (fallback) | `divi_contact_form_submitted` | Divi 5 named action hook. |

---

## Configuration

### Email Settings tab

| Field | Description |
|---|---|
| Email Subject | Subject line sent to the submitter |
| Email Body | Plain-text body — supports placeholders (see below) |
| From Name | Display name shown in the recipient's inbox |
| From Email | Sending address (must be authorised by your mail server / SPF) |

### Available placeholders

| Placeholder | Replaced with |
|---|---|
| `{name}` | Submitter's name (falls back to email if not found) |
| `{email}` | Submitter's email address |
| `{site_name}` | Your site name |
| `{site_url}` | Your site URL |
| `{date}` | Submission date |
| `{time}` | Submission time |
| `{field_id}` | Any Divi form field — wrap its ID in braces |

### Security tab

| Option | Description |
|---|---|
| Enable confirmation emails | Master on/off — disable without deactivating the plugin |
| Rate limit (per IP / hour) | Max emails from the same IP per hour (0 = unlimited) |
| Blocked email domains | Comma-separated domains to silently ignore (e.g. `tempmail.com`) |
| Blocked keywords | Comma-separated — suppresses send if any field contains the word |
| Require valid MX record | DNS check before sending (may be slow on some hosts) |
| Log blocked attempts | Write a Blocked row to the log when a send is suppressed |
| reCAPTCHA v3 — Site Key | Public key from [Google reCAPTCHA Admin Console](https://www.google.com/recaptcha/admin). Leave blank to disable. |
| reCAPTCHA v3 — Secret Key | Secret key used for server-side verification. Never expose publicly. |
| reCAPTCHA v3 — Minimum score | `0.0` = definitely a bot · `1.0` = definitely human. Recommended: `0.5`. |

When both reCAPTCHA keys are saved the plugin automatically:
1. Loads the invisible reCAPTCHA v3 script on every frontend page
2. Attaches the token + a WordPress nonce to every Divi form AJAX submission (Divi 4 & 5)
3. Verifies the token server-side; blocks submissions below the minimum score
4. Rejects direct AJAX calls from bots that never loaded the page (nonce guard)

### Logs tab

- Shows all sent / failed / blocked emails, newest first, 25 per page
- Status colours: **green** = Sent, **red** = Failed, **amber** = Blocked
- "Clear all logs" button with confirmation dialog

### Diagnostics tab

- **Send test email** — triggers a real confirmation email to any address without needing a form submission. Confirms your SMTP/mail setup is working.
- **System information** — plugin version, WordPress version, PHP version, active theme, Divi detection status, `wp_mail()` availability, current From email, plugin enabled state, log table existence, and `checkdnsrr()` availability.

---

## Translations

Language files live in the `languages/` directory. WordPress loads the correct one automatically based on **Settings → General → Site Language**.

| Locale | Language | Status |
|---|---|---|
| `en` | English | Built-in (no file needed) |
| `fa_IR` | Persian / فارسی | ✓ Included (59 strings) |
| `de_DE` | German / Deutsch | ✓ Included (59 strings) |

To add a new language or update an existing one:
1. Edit or create `languages/divi-contact-confirmation-{locale}.po`
2. Run `python bin/compile-mo.py` to compile the `.mo` binary
3. Upload both files to your server

---

## Developer hooks

```php
// Modify the subject before sending
add_filter( 'dcc_confirmation_subject', function( $subject, $email, $fields ) {
    return 'Custom subject';
}, 10, 3 );

// Modify the body before sending
add_filter( 'dcc_confirmation_body', function( $body, $email, $fields ) {
    return 'Custom body';
}, 10, 3 );

// Modify the mail headers
add_filter( 'dcc_confirmation_headers', function( $headers, $email, $fields ) {
    $headers[] = 'Bcc: archive@example.com';
    return $headers;
}, 10, 3 );

// Veto sending programmatically (return false or a reason string to block)
add_filter( 'dcc_should_send', function( $result, $email, $fields ) {
    if ( /* your condition */ false ) {
        return 'custom_block_reason';
    }
    return $result;
}, 10, 3 );
```

---

## Frequently Asked Questions

**The email is not sending — what should I check?**
1. Go to **Settings → Divi Confirmation → Diagnostics** and click **Send test email**.
2. If the test also fails, WordPress itself cannot send mail — install **WP Mail SMTP** and connect it to a real mail provider (Gmail, Mailgun, SendGrid, etc.).
3. If the test succeeds but real form submissions don't appear in the log, make sure the Divi form has an email field (the plugin scans all field values for a valid email address).

**Does this work with all Divi contact forms on the page?**  
Yes. The hook fires for every Divi contact form submission site-wide.

**Can I send an HTML email instead of plain text?**  
Add this to your theme's `functions.php`:
```php
add_filter( 'dcc_confirmation_headers', function( $headers ) {
    foreach ( $headers as $i => $h ) {
        if ( str_starts_with( $h, 'Content-Type' ) ) {
            $headers[ $i ] = 'Content-Type: text/html; charset=UTF-8';
        }
    }
    return $headers;
} );
```
Then write HTML directly in the Email Body field.

**Where is data stored?**  
Options in `wp_options` (prefixed `dcc_`). Logs in the `wp_dcc_log` custom table. Everything is removed cleanly on uninstall.

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

---

## Credits

Developed by [Mohammad Babaei](https://adschi.com).  
Licensed under [GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html).
