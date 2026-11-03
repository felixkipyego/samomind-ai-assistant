# SamoMind AI Assistant: architecture

> **Pending upstream revision.** `docs/API_CONTRACT.md` is still SamoMind
> commit `98d55cc` (upstream unchanged on 2026-11-03). Felix's decisions of
> 2026-11-03 override it until SamoMind's design doc is revised; the next
> task refreshes the copy:
>
> 1. Deactivation sends nothing (contract 9 says `disconnect` on
>    deactivation). Uninstall still disconnects.
> 2. The dashboard wins: on `source_disconnected` (and `unknown_source`)
>    the plugin forgets the source and waits for an admin to click Connect
>    (contract 5.4 says "call `connect` again").

This plugin copies the structure of the reference plugin
`onsite-training-cost-estimator` (OTCE) one for one. None of its business
logic is used, only the shape: file layout, loading, naming, settings and
hook style. Where this plugin needs something the reference has no example
of, the adaptation is listed with a "why" line.

The API contract is `docs/API_CONTRACT.md` (a pinned copy of SamoMind's
`docs/ingest/PUSH_INGESTION_DESIGN.md`). Where this file and the contract
differ, the contract wins.

## 1. Conventions

| Convention | Reference example | SamoMind value |
|---|---|---|
| Main plugin file | `onsite-training-cost-estimator.php` | `samomind-ai-assistant.php` |
| Plugin header | Plugin Name, URI, Description, Version, Author, Text Domain, Domain Path `/languages`, License | same fields, plus Requires at least `6.0`, Requires PHP `7.4`, License `GPL-2.0-or-later` |
| Direct access guard | `if ( ! defined( 'ABSPATH' ) ) exit;` at the top of every PHP file | same guard, written with braces (`{ exit; }`) so it passes WPCS |
| Silence files | `index.php` with `<?php // Silence is golden.` in every folder | same, in every shipped folder |
| Constants prefix | `OTCE_NAME`, `OTCE_VERSION`, `OTCE_PLUGIN_FILE`, `OTCE_PLUGIN_BASE`, `OTCE_PLUGIN_DIR`, `OTCE_PLUGIN_URL` | `SAMOMIND_NAME`, `SAMOMIND_VERSION`, `SAMOMIND_PLUGIN_FILE`, `SAMOMIND_PLUGIN_BASE`, `SAMOMIND_PLUGIN_DIR`, `SAMOMIND_PLUGIN_URL` |
| `@package` tag | `OTCE` | `SAMOMIND` |
| PHP namespace | none (global, prefixed classes) | none |
| Main class | `final class Onsite_Training_Estimator`, singleton, in `core/class-onsite-training-estimator.php` | `final class SamoMind_AI_Assistant` in `core/class-samomind-ai-assistant.php` |
| Accessor function | `OTCE()` returns the singleton | `SAMOMIND()` |
| Bootstrap sequence | `instance()`: `base_hooks()`, `includes()`, `new OTCE_Helpers`, `new OTCE_Settings`, `new OTCE_Run`, `do_action( 'OTCE/plugin_loaded' )` | same order; the instance also holds the service objects (section 3) before `new SAMOMIND_Run` |
| Feature classes | `OTCE_Run::add_hooks()` creates `new OTCE_Shortcode()` and `new OTCE_Admin()`, each hooking itself in its constructor | `SAMOMIND_Run::add_hooks()` creates `new SAMOMIND_Embed()` and `new SAMOMIND_Admin()` the same way |
| Class loading | `require_once` list in `includes()`, no autoloader | same; Composer is dev tooling only |
| Class prefix | `OTCE_Helpers`, `OTCE_Settings`, `OTCE_Run`, `OTCE_Admin` ... | `SAMOMIND_Helpers`, `SAMOMIND_Settings`, `SAMOMIND_Run`, `SAMOMIND_Admin` ... |
| Class files | `core/includes/classes/class-otce-<name>.php` | `core/includes/classes/class-samomind-<name>.php` |
| Hook registration | constructor calls `add_hooks()`; `add_action( ..., array( $this, 'method' ) )` | same |
| Custom hook names | `OTCE/plugin_loaded`, `OTCE/settings/get_plugin_name`, `OTCE/minimum_travel_cost` | `SAMOMIND/plugin_loaded`, `SAMOMIND/settings/get_plugin_name`, `SAMOMIND/content/post_types` ... |
| Option names | `otce_origin_zip`, `otce_courses` | `samomind_api_base_url`, `samomind_key_id` ... |
| Option getters | methods on `OTCE_Helpers` (`get_origin_zip()`) | methods on `SAMOMIND_Helpers` (`get_api_base_url()`) |
| Settings class | `OTCE_Settings`: plugin name accessor behind a filter | `SAMOMIND_Settings`: same |
| Settings page | `register_setting()` in group `otce_settings_group`, form posts to `options.php` | group `samomind_settings_group`, same pattern |
| Admin actions | `admin_post_otce_<action>`, capability check then `check_admin_referer( 'otce_<action>' )`, redirect with a query flag | `admin_post_samomind_<action>`, same order |
| Admin menu | `add_menu_page` slug `otce-estimator`, two submenus (`otce-courses`, `otce-settings`), duplicate top entry removed | slug `samomind-assistant`, submenus `samomind-status` and `samomind-settings`, duplicate removed |
| Capability | `manage_options` | `manage_options` |
| AJAX | `wp_ajax_otce_<action>`, `check_ajax_referer( 'otce_nonce', 'nonce' )`, `wp_localize_script` object `otce_params` | `wp_ajax_samomind_sync_progress`, nonce `samomind_nonce`, object `samomind_params` |
| Admin assets | `core/includes/assets/css/backend-styles.css`, `js/backend-scripts.js`, handles `otce-backend-*`, loaded only on screens whose id contains `otce` | same paths, handles `samomind-backend-*`, loaded only on screens whose id contains `samomind` |
| CSS class prefix | `otce-` (`otce-admin-wrap`, `otce-card`) | `samomind-` |
| Plugin action link | "Settings" link via `plugin_action_links_` + base | same, to the status page |
| Front end output | shortcode class `OTCE_Shortcode` | `SAMOMIND_Embed` on `wp_footer` |
| Outbound HTTP | `OTCE_Distance`: endpoint URLs as class constants, `wp_remote_get`, returns `array` or `WP_Error` | `SAMOMIND_Client`: path prefix as a class constant, `wp_remote_post`, returns `array` or `WP_Error` |
| i18n | text domain `onsite-training-cost-estimator`, `load_plugin_textdomain` on `plugins_loaded`, `/languages` | `samomind-ai-assistant`, same hook, `/languages` |
| Docblocks | class docblock with `@package`, `@subpackage Classes/<Class>`, `@since`; method docblocks with `@since`, `@param`, `@return` | same |
| Formatting | tabs, `array()` long syntax, WordPress spacing | same |
| Templates folder | `templates/index.php` only | same |
| Uninstall | none | `uninstall.php` (adaptation A6) |
| Tests, tooling | none | Composer dev tools, PHPUnit with Brain Monkey, PHPCS (adaptation A9) |

### Naming decisions

- Prefix `SAMOMIND` / `samomind`. The reference takes the initials of its
  name (`OTCE`); the initials here would be `SAA`, which is too short to be
  safe in the global namespace (WordPress.org asks for a prefix of at least
  four characters). The product name is the same idea: one short, unique
  word.
- Hook names keep the reference's `PREFIX/area/name` shape, even though WPCS
  prefers lowercase with underscores. The ruleset excludes those two
  sniffs, with a comment.
- `SAMOMIND()` keeps the reference's uppercase accessor; the ruleset
  excludes the function name sniff for that one file.

## 2. File layout

```
samomind-ai-assistant/
  samomind-ai-assistant.php        header, constants, require main class, SAMOMIND(), activation/deactivation hooks
  uninstall.php                    removes everything the plugin stored (A6)
  index.php
  core/
    index.php
    class-samomind-ai-assistant.php    singleton, includes(), base_hooks(), textdomain
    includes/
      index.php
      classes/
        index.php
        class-samomind-helpers.php     option getters, secret lookup, site identity
        class-samomind-settings.php    plugin name accessor
        class-samomind-run.php         registers hooks, assets, action link, AJAX, CLI
        class-samomind-admin.php       menu, Settings API, admin-post handlers, pages
        class-samomind-client.php      signed requests to the ingest API (A2)
        class-samomind-log.php         capped request log (A5)
        class-samomind-content.php     what is eligible, document mapping, inventory
        class-samomind-connection.php  connect, disconnect, checkin, connection state
        class-samomind-sync.php        queue, full sync, reconcile, worker (A1)
        class-samomind-scheduler.php   Action Scheduler or WP-Cron (A1)
        class-samomind-embed.php       widget snippet sanitiser and footer output
        class-samomind-cli.php         WP-CLI commands (A7)
      assets/
        index.php
        css/backend-styles.css
        js/backend-scripts.js
  templates/index.php
  languages/index.php
  docs/  tests/  composer.json  phpcs.xml.dist  phpunit.xml.dist  .github/  .wp-env.json   (not shipped, see .distignore)
```

## 3. Bootstrap

1. `samomind-ai-assistant.php` defines the constants, registers the
   activation and deactivation hooks, requires the main class and calls
   `SAMOMIND()`.
2. `SamoMind_AI_Assistant::instance()` runs `base_hooks()` (text domain),
   `includes()` (the `require_once` list), then creates, in order:
   `helpers`, `settings`, `log`, `content`, `scheduler`, `connection`,
   `sync`, and finally `new SAMOMIND_Run()`, then fires
   `SAMOMIND/plugin_loaded`.
3. `SAMOMIND_Run::add_hooks()` registers every WordPress hook: action link,
   assets, the footer embed (`new SAMOMIND_Embed()`), the admin
   (`new SAMOMIND_Admin()`), AJAX, post events, cron hooks and, under
   WP-CLI, the `samomind` command.

Service objects hang off the singleton as public properties, like the
reference's `helpers` and `settings`, so callers write
`SAMOMIND()->sync->start_full_sync()`.

## 4. Storage

All plain options, prefix `samomind_`, created with autoload off except the
two read on every front-end request.

| Option | Autoload | Content |
|---|---|---|
| `samomind_api_base_url` | no | API base URL, default `https://api.samomind.com` |
| `samomind_key_id` | no | ingest key id (UUID) |
| `samomind_api_secret` | no | ingest secret; ignored when `SAMOMIND_API_SECRET` is defined |
| `samomind_embed_snippet` | yes | validated widget `<script>` tag, empty by default |
| `samomind_connection` | no | source id, connected time, limits, check-in interval and time, last error, block state, failure start |
| `samomind_sync` | no | full sync state and progress, reconcile state |
| `samomind_queue` | no | pending pushes and deletes per external id, retry state |
| `samomind_log` | no | last 200 request rows: time, endpoint, HTTP status, code |
| `samomind_lock` | no | worker lock (time it was taken) |

Constant: `SAMOMIND_API_SECRET` in `wp-config.php` wins over the option and
makes the field read-only.

## 5. Adaptations (things the reference has no example of)

**A1. Background jobs.** `SAMOMIND_Scheduler` schedules one worker hook,
`SAMOMIND/worker`, through Action Scheduler (group `samomind`) when
`as_schedule_single_action` exists (WooCommerce ships it), otherwise through
`wp_schedule_single_event`. An hourly WP-Cron event, `SAMOMIND/hourly`,
checks in when the server's `checkin_interval_seconds` has passed, then
queues a reconcile. The worker holds an option lock so only one request is
in flight per site (contract 5.7).
Why: the reference only answers user requests; a sync has to run outside a
page load, and WP-Cron is the only scheduler every site has.

**A2. Outbound HTTP and signing.** `SAMOMIND_Client` is the only class that
calls `wp_remote_post`, and only to the configured base URL. It builds the
contract 5.2 canonical string, signs it, sends the 5.1 headers, a 30 s
timeout, and turns errors into `WP_Error( code, message, data )` where data
holds the HTTP status, `retryable` and `retry_after`.
Why: the reference's HTTP calls are unsigned GETs to public services.

**A3. Secret storage.** Option with autoload off, never rendered back
(masked field, separate "Replace secret" form posted to admin-post), never
logged, never sent anywhere but as the HMAC key. A constant overrides it.
Why: the reference stores no credentials.

**A4. Idempotency keys.** User actions (connect, disconnect, manual
check-in) get a fresh UUID key. Background requests get a key derived from
the event: `wp-` + the first 40 hex of `sha256( scope + "\n" + parts )`,
where parts are, for a push, `push:<id>:<modified_gmt>` per item, and for a
sync batch the run id and cursor. A WP-Cron re-run of the same work builds
the same key, so the server answers `duplicate_request` instead of applying
it twice.
Why: contract 5.1 and 5.7; WP-Cron can run an event twice.

**A5. Request log.** A capped option (200 rows, newest first) holding time,
endpoint, HTTP status and code, never a body or header. Shown on the status
page with a "Clear log" button.
Why: the reference has no logging; a custom table is more than 200 rows
need.

**A6. Activation, deactivation, uninstall.** Activation creates the
options with autoload off and schedules the hourly event. Deactivation
clears scheduled work and the lock and sends nothing; the source id and
settings stay. `uninstall.php` calls `disconnect` with reason `uninstall`
if a source id is stored, then deletes every option and scheduled event.
Why: the reference leaves its options behind. Deactivation is often
temporary (debugging, updates), and removing every document each time
would force a full sync on return (Felix, 2026-11-03; differs from
contract 9, see the note at the top).

**A7. WP-CLI.** `SAMOMIND_CLI`, registered as `wp samomind` only when
`WP_CLI` is defined. Each command calls the same service method the admin
button calls; `sync` and `reconcile` then drive the worker in the
foreground until the queue is empty.
Why: no CLI in the reference.

**A8. Post events.** `transition_post_status` and `before_delete_post`
put the post's external id in the queue (push or delete, latest wins, which
also debounces rapid saves) and schedule the worker 30 seconds out. The
document body is built when the worker runs, so it reflects the final
saved state.
Why: no content sync in the reference.

**A9. Tooling.** Composer (dev only): PHPCS with WordPress Coding
Standards and PHPCompatibilityWP, PHPUnit 9.6, Brain Monkey. Unit tests
run without WordPress. CI on GitHub Actions: PHPCS, PHP 7.4 compatibility,
unit tests on PHP 7.4, 8.1, 8.3. `composer smoke` runs a wp-env smoke test
(activate, open both admin pages, `wp samomind status`) where Docker is
available. The ruleset is WordPress with these exclusions, each commented:
no file docblock (the reference documents classes), the reference's
`PREFIX/area/name` hook names, its `###` banner comments, the uppercase
`SAMOMIND()` accessor, and unused `$args` in WP-CLI commands.
Why: the reference has no tests or tooling.

## 6. Request flows (contract section 5)

| Flow | Calls |
|---|---|
| Connect (button, CLI) | `/connect`, store `source_id`, start a full sync |
| Full sync (connect, "Sync now", CLI) | `/sync begin`, `/documents` batches in post id order with `sync_cursor` = last post id, `/sync complete`, then a reconcile |
| Post published or updated | `/documents` (batched with other pending pushes) |
| Trash, unpublish, private, password, excluded, deleted | `/documents/delete` |
| Hourly tick, interval passed | `/checkin`, then reconcile pages (`/reconcile`), push `missing` and `stale` |
| Disconnect (button, CLI, uninstall) | `/disconnect` |
| Deactivation | nothing sent |

Batches hold at most `limits.max_documents_per_push` documents (50) and stay
under `limits.max_body_bytes` (2 MiB); a 413 halves the next batch. A
per-item `plan_page_cap` stops a full sync and shows the message.

Error handling follows contract 5.4: 429, 5xx and network errors retry with
the same key and the 5.7 backoff (1 min, 5 min, 15 min, 1 h, 6 h, then
daily), after 24 h of failures an admin notice appears;
`invalid_signature` and `stale_timestamp` retry once with a fresh timestamp
inside the client; `unknown_source` and `source_disconnected` mean the
dashboard wins: the source id, queue and sync state are dropped, the
connection is blocked ("Disconnected from the SamoMind dashboard."), the
worker and the hourly tick send nothing, and `/connect` is called only from
the Connect button or `wp samomind connect`; `duplicate_request` counts as applied; `invalid_key`, `key_revoked`,
`unsupported_api_version`, `tenant_suspended` and `source_disabled` block
background work until settings change or a check-in succeeds; other 4xx
drop the request and record the error.

## 7. Hooks and filters for site owners

| Filter | Default | Use |
|---|---|---|
| `SAMOMIND/content/post_types` | post, page, public custom post types, `product` with WooCommerce | add or remove post types |
| `SAMOMIND/content/excluded_ids` | `array()` | post ids never sent |
| `SAMOMIND/settings/get_plugin_name` | `SamoMind AI Assistant` | same as the reference |

Action: `SAMOMIND/plugin_loaded` after setup.

## 8. Where the contract decided

The 7.6.b brief and the contract differ in four places; the contract wins.

| Brief | Contract | Built |
|---|---|---|
| `external_id` = post type + ":" + post id | 9: "`external_id` is the post id as a string" | post id as a string |
| hourly check-in | 5.5 and 9: `checkin_interval_seconds` 86400, "check-in then reconcile daily" | hourly tick that checks in once the server's interval has passed, then reconciles |
| uninstall does not call disconnect | 9: "`disconnect` on deactivation and uninstall" | uninstall disconnects when a source id is stored; deactivation sends nothing (Felix's decision 1, 2026-11-03, overrides the contract here) |
| (not in the brief) | 5.4: on `unknown_source` and `source_disconnected`, "call `connect` again" | never reconnects on its own; blocked until Connect is clicked (Felix's decision 2, 2026-11-03, overrides the contract here) |
| status shows the document count reported by the API | no connector endpoint returns a count (the count is in the dashboard's session-only overview) | the status page shows the plugin's own counts from the last full sync and says the dashboard holds the indexed count |

Product price goes in `fields.price` (contract 4), not in `text`; terms of
public taxonomies go in `fields.categories`.
