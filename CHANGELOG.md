# Changelog

## Unreleased (0.4.0)

Task F-3 T3. Built on a branch; the released version stays 0.3.6 and
nothing is submitted to WordPress.org.

- Added: order status in the chat, built against SamoMind's
  docs/design/F-3-order-status.md (contract copied to
  docs/ORDERS_CONTRACT.md). Off by default and opt-in: tick the box, accept
  the versioned order-data terms and paste an orders key (separate from the
  ingest key). "Share tracking" is on and "Share item names" is off by
  default.
- Added: signed REST endpoints for Samomind under
  /wp-json/samomind/v1/orders/ (start, verify, account/redeem, lookup, end,
  ping): a signature with its own label, a 120 second window, a stored
  nonce that fails closed, a signed path, signed answers and generic error
  bodies, and the limits Samomind sends held to hard ceilings.
- Added: the email code (6 digits from a CSPRNG, stored as an HMAC, 10
  minutes, 3 tries, a resend gap and a cap, a cap on mail from the whole
  shop), sent from WooCommerce's sender on the shutdown hook after the
  response has left, with the same answer and a 500 ms floor on every path.
- Added: the logged-in path, a same-origin account proof (cookie and nonce,
  no CORS, never cached) that writes the shop's own one-time record, and
  grants that end when the WordPress login session ends.
- Added: lookups through WooCommerce's CRUD layer only (works with HPOS on
  or off), only the fields in the design, the fixed status vocabulary,
  sequential order numbers, and tracking from WooCommerce Shipment Tracking
  or Advanced Shipment Tracking.
- Changed: when the shop has opted in, connect and check-in also report the
  order status capabilities. Nothing new is sent before that.
- Tests: an integration suite in wp-env with WooCommerce, run with HPOS off
  and on (`composer integration`), and a CI job for it.

## 0.3.6 (2026-11-12)

Step 7.6.m.

- Fixed: stock and price changes made by orders, cancelled and refunded
  orders, the REST API, the CSV importer, ERP and POS plugins, variation
  saves and scheduled sales did not reach Samomind until someone edited the
  product. WooCommerce saves those with its own code, which skips the post
  save hooks the plugin listened to. The plugin now also queues the product
  on WooCommerce's stock, stock status and product update actions (a
  variation queues its parent), through the same 30 second wait.
- Changed: the price is built from WooCommerce's own prices and sent as
  clean text, "$70.00 (was $100.00)" or "$30.00 to $50.00 (some on sale)",
  in the shop's tax display, currency and number format, with a price suffix
  as plain text. It no longer carries screen-reader text, and a large sale
  price is no longer cut off at 64 characters.
- Hidden products: confirmed that a product with catalog visibility Hidden
  is never sent, and that a product turned Hidden by a change that skips the
  save hooks is removed from Samomind. Shop only and Search results only
  products are sent.
- New: a dismissible notice on the Status and Settings screens when
  WooCommerce is active but Products is not ticked under What is synced. The
  selection is never changed for you.

## 0.3.5 (2026-11-11)

Step 7.6.i.

- Changed: new name, Samomind (lower-case m), in every text the user sees:
  the plugin name, admin screens, notices, WP-CLI messages and the readme.
  The slug, text domain, option names, hooks and the `wp samomind` command
  are unchanged.
- Changed: signup at https://app.samomind.com is open to everyone with a
  7-day free trial. The readme no longer says access is by invitation.
- Plugin URI is now https://samomind.com/help/wordpress-plugin/, and the
  Installation section links to the full guide.
- New WordPress.org icons, banners and two settings screenshots.
- Readme and reviewer note: the External services section now says the chat
  window footer shows "Powered by Samomind" with a link to samomind.com when
  the widget is on (removable on higher plans), and drops the "no telemetry"
  wording in favour of "no tracking or usage analytics".
- No change in behaviour.

## 0.3.4 (2026-11-07)

Step 7.6.d-review-b.

- Changed: escaping and autoload hardening for the WordPress.org review. The
  health notice escapes every value in the output call itself, the secret
  form handler checks its nonce in its own body, and the key id and API base
  URL are never created as autoloaded options.
- Clearer External services section in the readme: the widget requests the
  visitor's browser makes, and what SamoMind servers see. How to get an
  account (https://app.samomind.com, by invitation) is in the Description
  and the FAQ.

## 0.3.3 (2026-11-07)

Step 7.6.h.

- Changed: new installs now sync only posts, pages and WooCommerce products by
  default; other content types can be ticked in Settings. Existing sites keep
  their current selection.
- A new install saves its selection (`samomind_post_types`) at activation.
  An existing site writes nothing on update and syncs the same types as
  before. When its owner saves the settings form, the ticked types are stored
  from then on, and deletes are queued only for types unticked in that save.

## 0.3.2 (2026-11-07)

Step 7.6.g.

- Fixed: keeps the chat widget working with Jetpack Boost, Hummingbird,
  Perfmatters, FlyingPress and NitroPack optimisation. The script is added
  to Hummingbird's delay exclusions and its combine answer, Perfmatters'
  delay, defer and minify exclusions and FlyingPress' minify exclusion, plus its
  defer and delay exclusions where the installed version supports them, and
  the tag carries `data-jetpack-boost="ignore"` and `nitro-exclude`.
- W3 Total Cache needs no change: it only touches an external script that is
  listed under its minify external files.

## 0.3.1 (2026-11-06)

Step 7.6.f.

- Fixed: keeps the chat widget working when SiteGround Speed Optimizer
  combines JavaScript files. Combine copied `widget.js` into its own file
  and removed our tag, so `data-site-key` and `data-base-url` were lost and
  no chat showed. The script is now excluded from Speed Optimizer's combine
  (by tag id and by URL) and from its defer option.

## 0.3.0 (2026-11-06)

Step 7.6.e: the chat widget is a plugin setting, no pasted script.

- Changed: SamoMind > Settings > Chat widget has a switch ("Show the
  SamoMind chat widget on this site", off by default) and a Site key field.
  The plugin enqueues `https://widget.samomind.com/widget.js` in the footer
  with the `defer` strategy and adds `data-site-key` and `data-base-url`
  through `wp_script_attributes`. No inline script, so Wordfence and Sucuri
  have nothing to flag. It never loads in wp-admin, the login page, feeds,
  REST responses, embeds or previews.
- Changed: the site key is checked against the `pk_live_` format as typed;
  markup is refused, the previous key is kept.
- New: the settings page shows the exact address to add to Allowed origins
  in the SamoMind dashboard, computed from `home_url()`.
- New: the script is excluded from combine, minify and delay in WP Rocket,
  LiteSpeed Cache and Autoptimize.
- Removed: the Embed snippet field. A site that had pasted a snippet is
  migrated once (key moved, widget turned on, old option deleted).
- Changed: requires WordPress 6.4 (`wp_script_attributes` reaches enqueued
  scripts from 6.4).
- readme.txt: External services names `widget.samomind.com`, what is sent,
  and links the terms and privacy pages.

## 0.2.0 (2026-11-05)

Fix round after the live acceptance run on a production site built with
Elementor (step 7.6.b2).

- Fixed: an edit could reach SamoMind with the old text. Saves were queued
  only from the status hook, which fires before a page builder writes its
  layout, and a worker run for any reason sent the queued id at once. Now
  `wp_after_insert_post` (block editor, Classic, Quick Edit, REST) and
  changes to the storage meta keys of Elementor, Beaver Builder, Bricks,
  Oxygen, Divi, WPBakery and Avada queue the post id; the worker sends an id
  30 seconds after its last save (filter `SAMOMIND/sync/debounce_seconds`)
  and reads the post fresh then. Each save gets its own Idempotency-Key.
- New: page builder adapters (Elementor, Beaver Builder, Divi 4 and 5,
  WPBakery, Avada; Bricks and Oxygen by fallback), a shortcode-stripping
  fallback that never sends raw shortcodes, "Pages that may be incomplete"
  on the Status page, and the `SAMOMIND/content/text` filter.
- Fixed: ticking a post type again did not send its items; only Sync now
  did. It now queues pushes at once and replaces any queued delete.
- Fixed: the Status page kept showing "Connected" after a disconnect in the
  SamoMind dashboard. Opening it now checks in (at most once a minute).
- Changed: the Status page shows "Included on this site" (published items of
  the ticked types), separate from the last full sync's counts.

Also since 0.1.0 (never released separately):

- `docs/API_CONTRACT.md` refreshed to SamoMind `ba44704` (7.6.a1: deactivation
  does nothing server-side, a dashboard disconnect wins, indexed count is an
  open item). No change to the plugin's behaviour.
- readme.txt: FAQ rewritten around the five questions a site owner asks
  first, a Screenshots section (images to come, see `.wordpress-org/`), and
  External services moved under Description so the sections keep the
  WordPress.org order.
- Development: Plugin Check static rules (`composer plugin-check`), the
  translation template, `composer build` for the release zip and a Release
  workflow on `v*` tags. `load_plugin_textdomain` is no longer called
  (WordPress loads WordPress.org translations itself).

## 0.1.0 (2026-11-03)

First release, for the pilot (step 7.6.b). Built against SamoMind's push
ingestion contract at commit `98d55ccc0091a4bfb013302b5a82cf29b50bd872`
(`docs/API_CONTRACT.md`).

- Settings: API base URL, key id, write-only secret (or the
  `SAMOMIND_API_SECRET` constant), widget embed snippet.
- Signed requests (contract 5.2) with the pinned worked example as a test
  vector.
- Connect and disconnect; status page with connection, sync progress and a
  200-row request log.
- Post type checklist on the settings page (all ticked by default); unticking
  a type removes its items from SamoMind.
- Deactivation sends nothing and keeps the connection; uninstall disconnects.
- A disconnect from the SamoMind dashboard wins: the plugin stops and waits
  for an admin to click Connect.
- Full sync in the background (Action Scheduler or WP-Cron), resumable from
  the server's cursor, stopping on the plan's page cap.
- Pushes on publish and update, deletes on trash, unpublish, private,
  password and delete; deterministic idempotency keys per event.
- Daily check-in and reconcile; retries with the contract's backoff;
  admin notice after 24 hours of failures.
- Widget snippet in the footer, accepted only from widget.samomind.com.
- WP-CLI: `wp samomind status|connect|disconnect|sync|reconcile|checkin`.
- Uninstall disconnects a connected site, then removes all options,
  transients and scheduled events.
