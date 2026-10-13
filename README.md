# Bulk Mailing Lists

A WordPress plugin that lets administrators assemble mailing lists from WPForms entries or WooCommerce data and send bulk emails to them. Delivery goes through `wp_mail()`, so it uses whatever mailer the site is configured with (e.g. WP Mail SMTP).

## Email types

Every list is one of two types, which decides where its addresses come from and whether recipients can unsubscribe.

| | Marketing Email | Product Email |
|---|---|---|
| Source | Entries of a WPForms form, and/or WooCommerce customers | WooCommerce data |
| Intended for | Newsletters and promotions, to people who have agreed to receive them | Legal / product notices / other GDPR legitimate interest |
| Unsubscribe | Always included | Not possible |

## Sources

**WPForms**

- Pick a form, and optionally which field holds the email address and the name
- Every entry's address goes on the list; spam and trashed entries are skipped
- Requires WPForms Pro, because the free version does not store entries in the site's database
- A marketing list may use a form, WooCommerce customers, or both. An address that is in both appears once

**WooCommerce**

Choose applicable customers based on:
- All customers, optionally including registered customer accounts with no orders
- All customers who have purchased a product: pick a normal product, parent product, or a single variation
- All subscribers to a subscription product or subscription variation (requires WooCommerce Subscriptions); can be left empty for any/all subscription customers

Which order and subscription statuses are applicable can be set per list.

Product lists use these options directly. A **marketing** list uses them when **Also include WooCommerce customers** is ticked, which is how to email form subscribers and customers together. Only tick it when those customers have agreed to marketing email: the plugin includes everyone matching the options.

## Refreshing

Lists are rebuilt from their source:
- Once a day (WP-Cron event `mlp_daily_refresh`)
- When a list is saved
- On demand, with **Refresh now**
- Immediately before an email is sent

## Sending

- Compose in the visual (WYSIWYG) editor or as raw HTML code
- Preview, send yourself a test, and save drafts
- Emails are sent in the background in batches using Action Scheduler when available, otherwise WP-Cron. Batch size and interval are under **Mailing Lists -> Settings**
- Failed recipients are listed with the mailer's error and can be retried

Merge tags: `{name}` `{email}` `{site_name}` `{site_url}` `{unsubscribe_link}` `{unsubscribe_url}`

## Unsubscribing

Marketing Emails always carry a signed unsubscribe link, plus one-click `List-Unsubscribe` headers.

Unsubscribing opts the address out of marketing email as a whole, not just the one list:
- Its entries are deleted from every WPForms form that a marketing list uses
- It is removed from every marketing list and from any marketing email still waiting to be sent
- A keyed hash (HMAC-SHA256 with a per-site secret) of the address is stored so a WooCommerce customer is not added back by the next refresh. The address itself is not kept and the hash cannot be turned back into one. Signing up through the form again is a new opt-in and puts the person back on the list

Product lists ignore this record: a legal or product notice still reaches an unsubscribed customer.

Product Emails are designed strictly for legal / legitimate interest emails and thus get no unsubscribe link or header.

## Requirements

- WordPress 5.9+, PHP 7.4+
- WPForms Pro, for Marketing Email lists sourced from a form
- WooCommerce, for Product Email lists and for marketing lists that include customers (supports WooCommerce Subscriptions for building subscriber lists)
- WP Mail SMTP or any other `wp_mail()`-compatible mailer. An SMTP mailer is needed for the `List-Unsubscribe` header to be usable by mail clients
- A working WP-Cron. On low-traffic sites, trigger `wp-cron.php` from a real cron job so the daily refresh and send batches run on time

## Installation

1. Download `mailinglist-plugin-<version>.zip` from the [latest release](../../releases/latest). Do not use GitHub's "Source code" archive: it unpacks to a differently named folder, which WordPress treats as a different plugin.
2. In WordPress admin, go to **Plugins -> Add New Plugin -> Upload Plugin**, choose the zip and click **Install Now**.
3. Activate **Bulk Mailing Lists**. A **Mailing Lists** item appears in the admin menu.

## Developer hooks

| Hook | Type | Purpose |
|---|---|---|
| `mlp_capability` | filter | Capability required to use the plugin (default `manage_options`) |
| `mlp_email_html` | filter | Final HTML of each email |
| `mlp_email_headers` | filter | Headers of each email |
| `mlp_list_refreshed` | action | Fires after a list is rebuilt |
| `mlp_unsubscribed` | action | Fires after someone unsubscribes |

## Development

```
composer install
composer lint      # php -l over every file
composer phpcs     # WordPress coding standards
composer phpstan   # static analysis
```

### Tests

The PHPUnit suite runs on the WordPress test library against MySQL. WooCommerce is installed from wordpress.org; WPForms entries and WooCommerce Subscriptions are replaced by a fixture table and stubs in `tests/`.

```
bin/install-wp.sh latest yes     # WordPress + WooCommerce into /tmp/wordpress, creates the database
vendor/bin/phpunit
```

Connection settings come from `WP_DB_HOST`, `WP_DB_USER`, `WP_DB_PASSWORD`, `WP_DB_NAME` (defaults: `127.0.0.1`, `root`, empty, `wordpress_test`) and the WordPress location from `WP_CORE_DIR`. Set `MLP_SKIP_WOOCOMMERCE=1` and pass `--exclude-group woocommerce` to run without WooCommerce.

End-to-end tests are separate from the unit tests: `bin/e2e-http.sh` boots a real site and checks the public unsubscribe endpoint over HTTP. CI runs the unit tests in the `unit-tests` job matrix and the end-to-end tests in the `e2e-tests` job matrix.

### Releasing

1. Bump `Version:` and `MLP_VERSION` in `mailinglist-plugin.php` (they must match).
2. Tag and push: `git tag v1.2.3 && git push origin v1.2.3`.

The `Release` workflow re-runs CI, checks the tag matches the plugin version, builds `mailinglist-plugin-<version>.zip` with `bin/build-zip.sh` (development files are excluded via `.gitattributes`) and attaches it to a GitHub Release. Running the workflow manually builds the zip as a run artifact without publishing.

## License

[MIT](LICENSE)
