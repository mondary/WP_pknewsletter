# WP PK Newsletter

[🇬🇧 EN](README_en.md) · [🇫🇷 FR](README.md)

✨ WordPress plugin to manage a daily editorial newsletter with subscriber management, HTML digest rendering, subscription history, and built-in SMTP delivery.

## ✅ Features
- Dedicated admin dashboard with `Dashboard`, `Subscribers`, `Statistics`, and `Settings`
- Frontend subscription form via shortcode
- Subscriber management with manual add, CSV import/export, filters, pagination, and row actions
- Preserved subscription, unsubscribe, and resubscribe history
- HTML email digest with multiple layouts and visual themes
- Email preview directly in the admin
- Test digest sending to a dedicated email address, with day selection (`today` / `yesterday`) and layout selection
- SMTP built directly into the plugin
- Token-based unsubscribe links
- Sending stats and subscriber growth charts
- Dashboard `Posts du jour` preview showing both published and scheduled posts for the day, with a visual scheduled flag
- Optional frontend floating action button (bottom-right), configurable from settings

## 🧠 Usage
The plugin is meant to run an editorial digest newsletter from WordPress without relying on a third-party SaaS for the management UI.

Typical flow:
- posts published today are fetched from WordPress
- the digest is rendered as HTML
- a test email can be sent from the admin
- subscribers are managed in the `Subscribers` view
- real sending can then be handled through SMTP, for example Amazon SES

## ⚙️ Settings
In `WP PK Newsletter > Settings`, you can configure:
- brand identity
- sender email and `Reply-To`
- accent color
- logo URL
- digest layout
- email visual theme
- subject with `%date%`
- daily send hour
- maximum number of posts
- intro and footer text
- SMTP host / port / security / credentials
- newsletter floating button: show/hide, label, background color, and padding
- newsletter floating button: configurable radius and custom destination URL

## 🧾 Commands
The plugin does not expose a dedicated CLI command.

Main admin actions:
- `Send test digest`
- `Import CSV`
- `Export CSV`
- `Reactivate` / `Deactivate` a subscriber

Available shortcode:

```text
[wppk_newsletter_form]
```

## 📦 Build & Package
The plugin is designed to be shipped as a standard WordPress zip package.

Main files:
- `src/wppknewsletter.php`
- `src/includes/class-wppk-newsletter.php`
- `src/templates/email-digest.php`
- `src/assets/admin.css`
- `src/assets/form.css`

To package it:
- run:

```bash
scripts/build-plugin.sh
```

- pick up the generated archive from `extensions/`
- upload the archive from `Plugins > Add New > Upload Plugin`

The build script generates a zip with a stable root directory `WPpknewsletter/`, so WordPress replaces the installed plugin instead of creating a sibling plugin folder.

## 🧪 Installation
1. Upload the plugin into WordPress.
2. Activate `WP PK Newsletter`.
3. Open `WP PK Newsletter` in the admin.
4. Configure branding, digest settings, and SMTP.
5. Add a page with this shortcode:

```text
[wppk_newsletter_form]
```

## 📁 Subscriber CSV
Supported import format:

```text
email,status,source,signup_process,delivery_channel,content_mode,preferred_hour,confirmed
```

Useful exported columns:
- `created_at`
- `subscribed_at`
- `unsubscribed_at`
- `resubscribed_at`

## 🔌 SMTP
The plugin can send through `wp_mail()` or through its built-in SMTP configuration.

Amazon SES example:
- host: `email-smtp.eu-north-1.amazonaws.com`
- port: `587`
- security: `TLS`

## 🧾 Changelog
- `2.35`: auto-recovers stuck “sending” campaigns + flushes progress periodically + per-recipient error handling (avoids being stuck at 0/…)
- `2.34`: optional diagnostics panel `?wppk_diag=1` (PROD/DEV campaign payloads + latest send log) + logs an error if DB insert fails
- `2.33`: manual digest send now marks the daily campaign as `sent` and writes logs like the cron sender (prevents the dashboard from staying stuck on “In progress”) + dashboard shows PROD/DEV
- `2.32`: newsletter floating button now supports configurable radius and custom destination URL (default `https://mondary.design/newsletter/`)
- `2.31`: added configurable newsletter floating button (bottom-right) in settings (on/off, label, color, padding)
- `1.18`: larger email header logo, lighter thumbnail radius, SMTP password no longer prefilled in admin
- `1.17`: email visual adjustments
- `1.16`: subscriber chart switched to cumulative volume
- `1.15`: subscriber chart compacted
- `1.14`: `Send test` card moved above `Posts du jour` in the dashboard
- `1.13`: removed the standalone `Posts du jour` tab, stronger orange scheduled flag, stabilized zip packaging
- `1.12`: `Posts du jour` preview now includes published + scheduled posts with scheduled time
- `1.11`: versioning normalized to the `1.x` format
- `1.0.0`: first stable usable version

## 🔗 Links
- FR README: [README.md](README.md)
- Website: <https://mondary.design>
