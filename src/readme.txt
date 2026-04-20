=== PKnewsletter ===
Contributors: pouark
Tags: newsletter, email, digest, subscribers
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 2.36
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight daily digest newsletter with subscriber management, confirmation flow, and unsubscribe handling.

== Description ==

PKnewsletter lets you collect subscribers and send a daily digest email from your WordPress site.

Features:

* Subscriber list with confirmation flow
* Daily digest scheduler + batch sending
* Multiple email layouts
* One-click unsubscribe links

== Installation ==

1. Upload the plugin zip and activate it.
2. Go to "WP PK Newsletter" in your admin menu.
3. Configure sender details and scheduling.

== Frequently Asked Questions ==

= Does it support double opt-in? =
Yes. New subscribers must confirm via email.

== Changelog ==

= 2.36 =
* Cron: de-duplicate scheduled events for the digest hook (prevents double triggers after hosting migrations).
* Admin: diagnostics panel now shows cron hook occurrences + next scheduled run.
* DevOps: add REST sync endpoints (manifest + push) for automated deployments.

= 2.35 =
* Digest: auto-recovers stuck "sending" campaigns after 30 minutes (reset + restart on next trigger).
* Digest: batch sender now catches per-recipient errors and periodically flushes progress to the campaign option.

= 2.34 =
* Dashboard: optional diagnostics panel (?wppk_diag=1) showing PROD/DEV campaign payloads and the latest send log.
* Logs: write an event log if the send log insert fails (wpdb last_error).

= 2.33 =
* Digest: manual send now marks the daily campaign as "sent" and writes logs like the cron sender (prevents "Campagne du jour" from staying stuck on "En cours").
* Dashboard: "Campagne du jour" now shows PROD/DEV and hints when the other audience already sent.

= 2.32 =
* Settings: floating newsletter button now supports custom border radius and custom destination URL.
* Default destination URL set to https://mondary.design/newsletter/.

= 2.31 =
* Settings: added optional floating action button (bottom-right) linking to the newsletter page.
* Settings: floating button can be enabled/disabled and customized (label, background color, padding).

= 2.30 =
* Dashboard: "Désinscriptions" card now shows +subs / -unsubs over 7 days.

= 2.29 =
* Dashboard: Overview cards order updated.

= 2.28 =
* Dashboard: "Emails envoyés" now shows month-to-date total (resets on the 1st).

= 2.27 =
* Dashboard: real month-to-date cost estimate based on sent emails (auto resets on 1st of month).

= 2.26 =
* Dashboard: monthly estimated cost added to Overview.
* Settings: Jetpack Newsletter tools moved into Settings (no separate tab).

= 2.25 =
* Subscribers table: better alignment for row actions (Edit/Delete) and improved views tabs placement.

= 2.24 =
* Subscribers: Actifs/Inactifs/Corbeille views, soft delete (trash), and purge-from-trash flow.
