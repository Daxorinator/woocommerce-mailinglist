=== Bulk Mailing Lists ===
Requires at least: 5.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: MIT

Assemble mailing lists from WPForms entries or WooCommerce data and send bulk emails through wp_mail().

== Description ==

Adds a "Mailing Lists" menu for administrators.

= Email types =

* Marketing Email – the list is built from every entry submitted to a WPForms form you choose. Requires WPForms Pro (the free version does not store entries). Every email carries an unsubscribe link.
* Product Email – the list is built from WooCommerce data, for legal / product notices such as recalls. No unsubscribe link is added and recipients cannot unsubscribe.

= WooCommerce sources =

* All customers (optionally including registered customer accounts with no orders)
* All customers who have purchased a product – pick a product for all its variations, or a single variation
* All subscribers to a subscription product or variation (requires WooCommerce Subscriptions) – leave the product empty for any subscription

Order statuses and subscription statuses that count are configurable per list.

= Refreshing =

Lists are rebuilt from their source once a day (WP-Cron event `mlp_daily_refresh`), when saved, on demand with "Refresh now", and immediately before an email is sent. If a source is unavailable, the previous recipients are kept and the error is shown against the list.

= Sending =

Compose with the visual (WYSIWYG) editor or as raw HTML code, preview it, and send yourself a test. Emails are sent with wp_mail(), so delivery uses whatever WP Mail SMTP is configured with. Sending happens in the background in batches (Action Scheduler when available, otherwise WP-Cron); batch size and interval are under Mailing Lists → Settings. Failed recipients are listed with the mailer's error and can be retried.

Merge tags: {name} {email} {site_name} {site_url} {unsubscribe_link} {unsubscribe_url}

= Unsubscribing =

Marketing Emails include a signed unsubscribe link (and List-Unsubscribe one-click headers). Unsubscribing deletes that person's entries from the WPForms form the list is sourced from, and records the address so it stays off lists built from that form. Submitting the form again later re-subscribes them.

== Requirements for background tasks ==

The daily refresh and batch sending rely on WP-Cron. On low-traffic sites, trigger wp-cron.php from a real cron job so they run on time.

== Developer hooks ==

* `mlp_capability` (filter) – capability required, default `manage_options`
* `mlp_email_html`, `mlp_email_headers` (filters)
* `mlp_list_refreshed`, `mlp_unsubscribed` (actions)
