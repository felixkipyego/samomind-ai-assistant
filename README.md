# SamoMind AI Assistant

WordPress plugin that keeps a SamoMind AI assistant's knowledge base in sync
with the site's public content, and adds the SamoMind chat widget.

- Requires WordPress 6.0+, PHP 7.4+. GPL-2.0-or-later.
- Architecture and conventions: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)
- API contract (pinned copy): [docs/API_CONTRACT.md](docs/API_CONTRACT.md)
- Pilot acceptance checklist: [docs/ACCEPTANCE.md](docs/ACCEPTANCE.md)

## Install

1. Zip the plugin without the development files (`.distignore` lists
   them), or copy the folder to `wp-content/plugins/samomind-ai-assistant`.
2. Activate **SamoMind AI Assistant** in Plugins. A **SamoMind** menu
   appears with **Status** and **Settings**.

## Connect

1. In the SamoMind dashboard (app.samomind.com), open **Sources**, then the
   **WordPress** card, and click **Create an ingest key**. The dashboard
   shows a key id (a UUID) and a secret (`smik_...`). The secret is shown
   only once. Creating a new key revokes the old one at once, so every
   connected site stops until it gets the new key.
2. In WordPress, open **SamoMind > Settings**. Paste the secret and click
   **Save secret**. Paste the key id and click **Save Settings**. Leave
   the API base URL at `https://api.samomind.com` unless SamoMind gives you
   a staging address.

   To keep the secret out of the database, put it in `wp-config.php`
   instead; the constant wins and the field becomes read-only:

   ```php
   define( 'SAMOMIND_API_SECRET', 'smik_...' );
   ```

3. Open **SamoMind > Status** and click **Connect**. The site appears in
   the dashboard's WordPress card, and the first full sync starts in the
   background. A refusal is shown with the API's own message and code.

The saved secret is never shown again, never logged, and only used to sign
requests (HMAC-SHA256, contract section 5.2). It is never sent.

## What is synced

Published, public, not password-protected:

- pages and posts
- public custom post types (public and publicly queryable; never
  attachments or WordPress internals)
- WooCommerce products when WooCommerce is active, except those with
  catalog visibility "hidden"; orders, carts and customers are never read

Per item: the post id, permalink, title, text (the rendered content with
shortcodes and blocks applied, reduced to plain text, plus the excerpt and,
for products, the short description), kind (page, post, product or
custom), modified time, and the names of its public taxonomy terms. Products
add price and stock status.

Never sent: drafts, scheduled, pending, private or password-protected
content, users, visitors, comments, form entries, orders, media files.

When content changes:

| On the site | Request |
|---|---|
| publish or update | `/documents` (about 30 seconds later, batched) |
| trash, unpublish, make private, add a password, delete | `/documents/delete` |
| connect, "Sync now" | full sync: `/sync begin`, `/documents` batches, `/sync complete`, then reconcile |
| daily | `/checkin`, then `/reconcile` |
| uninstall, "Disconnect", `wp samomind disconnect` | `/disconnect` (SamoMind removes this site's documents) |
| deactivate | nothing; scheduled work stops until you reactivate |

Background work runs on Action Scheduler when WooCommerce provides it,
otherwise on WP-Cron. WP-Cron runs when the site gets visits; on a quiet
site, use a real cron job (`wp cron event run --due-now`).

If the site is disconnected in the SamoMind dashboard, the dashboard wins:
the plugin forgets the connection, sends nothing more, and shows
"Disconnected from the SamoMind dashboard." with a **Connect** button. It
never reconnects by itself.

The **Status** page shows the connection, sync progress, the last full
sync's counts and the last 200 requests (time, endpoint, HTTP status,
code; never bodies). The SamoMind API does not report the indexed page
count to the plugin; the dashboard shows it.

## Chat widget

Paste the snippet from the dashboard's **Widget** screen into
**SamoMind > Settings > Embed snippet**. It prints in the footer of every
front-end page. Only a single `<script>` tag from
`https://widget.samomind.com` with `data-*` attributes and `async` or
`defer` is accepted. Empty means off (the default).

## WP-CLI

```sh
wp samomind status
wp samomind connect
wp samomind sync          # full sync in the foreground
wp samomind reconcile
wp samomind checkin
wp samomind disconnect --yes
```

Each command runs the same code as the matching admin button.

## Filters

```php
// Post types to sync (default: post, page, public custom types, product with WooCommerce).
add_filter( 'SAMOMIND/content/post_types', function ( $types ) {
	return array_diff( $types, array( 'event' ) );
} );

// Post ids never sent.
add_filter( 'SAMOMIND/content/excluded_ids', function ( $ids ) {
	$ids[] = 123;
	return $ids;
} );
```

Attachments are never sent, whatever the filter returns. After changing a
filter, click **Sync now** (new exclusions are removed at the next
reconcile).

## Uninstall

Deactivating sends nothing to SamoMind: it only stops the scheduled work.
The documents stay in SamoMind and the connection is kept, so reactivating
picks up where it left off, without a full sync. Edits made while the
plugin was inactive are caught by the next daily reconcile (or click
**Reconcile now**).

Deleting the plugin disconnects the site if it is still connected
(SamoMind removes its documents), then removes every option, notice and
scheduled event it created.

## Development

```sh
composer install
composer lint     # PHPCS, WordPress Coding Standards (phpcs.xml.dist)
composer compat   # PHPCompatibilityWP, PHP 7.4 and up
composer test     # unit tests with Brain Monkey, no WordPress needed
composer smoke    # wp-env smoke test (Docker), then: npx @wordpress/env destroy
```

No build step. The tests never call the API; the client is mocked.
