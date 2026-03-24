# WP PK Newsletter

[🇬🇧 EN](README_en.md) · [🇫🇷 FR](README.md)

✨ WordPress plugin to manage a daily editorial newsletter with subscriber management, HTML digest rendering, subscription history, and built-in SMTP delivery.

## ✅ Features
- Dedicated admin dashboard with `Dashboard`, `Subscribers`, `Today posts`, `Statistics`, and `Settings`
- Frontend subscription form via shortcode
- Subscriber management with manual add, CSV import/export, filters, pagination, and row actions
- Preserved subscription, unsubscribe, and resubscribe history
- HTML email digest with multiple layouts and visual themes
- Email preview directly in the admin
- Test digest sending to a dedicated email address
- SMTP built directly into the plugin
- Token-based unsubscribe links
- Sending stats and subscriber growth charts

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
- use a build archive stored in `extensions/`
- upload the archive from `Plugins > Add New > Upload Plugin`

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
- `1.0.7`: non-destructive subscriber history, subscriber/unsubscriber chart, AWS free plan reminder, compact stats cards
- `1.0.6`: fixed the `List + thumbnails` layout
- `1.0.5`: subscriber pagination and per-page selector
- `1.0.0`: first stable usable version

## 🔗 Links
- FR README: [README.md](README.md)
- Website: <https://mondary.design>
