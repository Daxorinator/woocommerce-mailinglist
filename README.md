# Bulk Mailing Lists

A WordPress plugin that lets administrators assemble mailing lists from WPForms entries or WooCommerce data and send bulk emails to them. Delivery goes through `wp_mail()`, so it uses whatever mailer WP Mail SMTP is configured with.

## Email types

Every list is one of two types, which decides where its addresses come from and whether recipients can unsubscribe.

| | Marketing Email | Product Email |
|---|---|---|
| Source | Entries of a WPForms form | WooCommerce data |
| Intended for | Newsletters and promotions, where consent was collected by the form | Legal / product notices such as recalls |
| Unsubscribe | Always included | Not possible |

## Sources

**WPForms** — pick a form, and optionally which field holds the email address and the name. Every entry's address goes on the list; spam, trashed, partial and abandoned entries are skipped. Requires WPForms Pro, because the free version does not store entries in the site's database.

**WooCommerce**

- All customers, optionally including registered customer accounts with no orders
- All customers who have purchased a product — pick a product to cover all its variations, or a single variation
- All subscribers to a subscription product or variation (requires WooCommerce Subscriptions) — leave the product empty for any subscription

Which order statuses and subscription statuses count is set per list.

## Refreshing

Lists are rebuilt from their source:

- once a day (WP-Cron event `mlp_daily_refresh`)
- when a list is saved
- on demand, with **Refresh now**
- immediately before an email is sent

If a source is unavailable, the previous recipients are kept and the error is shown against the list.

## Sending

- Compose in the visual (WYSIWYG) editor or as raw HTML code
- Preview, send yourself a test, and save drafts
- Emails are sent in the background in batches — Action Scheduler when available, otherwise WP-Cron. Batch size and interval are under **Mailing Lists → Settings**
- Failed recipients are listed with the mailer's error and can be retried

Merge tags: `{name}` `{email}` `{site_name}` `{site_url}` `{unsubscribe_link}` `{unsubscribe_url}`

## Unsubscribing

Marketing Emails always carry a signed unsubscribe link, plus one-click `List-Unsubscribe` headers. Unsubscribing:

- deletes that person's entries from the WPForms form the list is sourced from
- records the address, so it stays off every list built from that form
- is undone if they submit the form again later, which counts as a new opt-in

The link opens a confirmation page rather than acting on click, so mail link-scanners cannot unsubscribe people.

Product Emails get no unsubscribe link or header.

## Requirements

- WordPress 5.8+, PHP 7.4+
- WPForms Pro, for Marketing Email lists
- WooCommerce, for Product Email lists (plus WooCommerce Subscriptions for subscriber lists)
- WP Mail SMTP or any other `wp_mail()`-compatible mailer. An SMTP mailer is needed for the `List-Unsubscribe` header to be usable by mail clients
- A working WP-Cron. On low-traffic sites, trigger `wp-cron.php` from a real cron job so the daily refresh and send batches run on time

## Installation

1. Download this repository as a zip, or clone it into `wp-content/plugins/`.
2. In WordPress admin, go to **Plugins → Add New Plugin → Upload Plugin**, choose the zip and click **Install Now**.
3. Activate **Bulk Mailing Lists**. A **Mailing Lists** item appears in the admin menu.

## Developer hooks

| Hook | Type | Purpose |
|---|---|---|
| `mlp_capability` | filter | Capability required to use the plugin (default `manage_options`) |
| `mlp_email_html` | filter | Final HTML of each email |
| `mlp_email_headers` | filter | Headers of each email |
| `mlp_list_refreshed` | action | Fires after a list is rebuilt |
| `mlp_unsubscribed` | action | Fires after someone unsubscribes |

## License

[MIT](LICENSE)
