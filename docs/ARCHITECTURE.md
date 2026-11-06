# SamoMind AI Assistant: architecture

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
| Bootstrap sequence | `instance()`: `base_hooks()`, `includes()`, `new OTCE_Helpers`, `new OTCE_Settings`, `new OTCE_Run`, `do_action( 'OTCE/plugin_loaded' )` | same order without `base_hooks()` (it only loaded the text domain, see i18n); the instance also holds the service objects (section 3) before `new SAMOMIND_Run` |
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
| Front end output | shortcode class `OTCE_Shortcode` | `SAMOMIND_Embed`: `wp_enqueue_script` on `wp_enqueue_scripts` (A13) |
| Outbound HTTP | `OTCE_Distance`: endpoint URLs as class constants, `wp_remote_get`, returns `array` or `WP_Error` | `SAMOMIND_Client`: path prefix as a class constant, `wp_remote_post`, returns `array` or `WP_Error` |
| i18n | text domain `onsite-training-cost-estimator`, `load_plugin_textdomain` on `plugins_loaded`, `/languages` | `samomind-ai-assistant`, `/languages` holds the `.pot`; no `load_plugin_textdomain` call, because WordPress loads translations for a WordPress.org plugin itself since 4.6 and Plugin Check flags the call |
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
    class-samomind-ai-assistant.php    singleton, includes(), activation and deactivation
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
        class-samomind-builders.php    adapter registry, fallback, quality list (A10)
        builders/                      interface-samomind-builder-adapter.php, class-samomind-builder-{base,core,elementor,beaver,bricks,oxygen,divi,wpbakery,avada}.php
        class-samomind-connection.php  connect, disconnect, checkin, connection state
        class-samomind-sync.php        queue, full sync, reconcile, worker (A1)
        class-samomind-scheduler.php   Action Scheduler or WP-Cron (A1)
        class-samomind-embed.php       widget enqueue, script attributes, optimiser exclusions
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
2. `SamoMind_AI_Assistant::instance()` runs `includes()` (the
   `require_once` list), then creates, in order: `helpers`, `settings`, `log`, `content`, `scheduler`, `connection`,
   `sync`, and finally `new SAMOMIND_Run()`, then fires
   `SAMOMIND/plugin_loaded`.
3. `SAMOMIND_Run::add_hooks()` registers every WordPress hook: action link,
   assets, the widget loader (`new SAMOMIND_Embed()`), the admin
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
| `samomind_widget_enabled` | yes | `1` or `0`, chat widget on or off, default `0` |
| `samomind_site_key` | yes | the widget site key (`pk_live_...`), empty by default; validated on save and again before use |
| `samomind_excluded_post_types` | no | post types unticked on the settings page; an exclusion list, so a newly registered type is synced until unticked |
| `samomind_connection` | no | source id, connected time, limits, check-in interval and time, last error, block state, failure start |
| `samomind_sync` | no | full sync state and progress, reconcile state |
| `samomind_queue` | no | pending pushes and deletes per external id (op, event id, earliest send time), event counter, retry state; ids only, never content |
| `samomind_incomplete` | no | posts whose text may be incomplete: post id, builder, reasons, time (200 at most; A10) |
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
where parts are, for a push, `push:<id>:<modified_gmt>@<enqueue time>.<counter>`
per item, and for a sync batch the run id and cursor. The event id is
stored in the queue, so a WP-Cron re-run or a retry of the same work builds
the same key and the server answers `duplicate_request` instead of applying
it twice. Every new save gets a new event id (the time and counter), because
a page builder can change the content without changing the post's modified
time, and a key reused for different content would be replayed and dropped
(7.6.b2).
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
would force a full sync on return (Felix, 2026-11-03; contract 9 since
7.6.a1).

**A7. WP-CLI.** `SAMOMIND_CLI`, registered as `wp samomind` only when
`WP_CLI` is defined. Each command calls the same service method the admin
button calls; `sync` and `reconcile` then drive the worker in the
foreground until the queue is empty.
Why: no CLI in the reference.

**A8. Post events.** Hooks only queue the post id; the body is built when
the worker sends it, from the post and its meta as they are then. Hooked:
`transition_post_status` and `before_delete_post` (status changes and
deletes); `wp_after_insert_post` (the block editor, Classic editor, Quick
Edit, REST and `wp_update_post()`, once meta and terms are written);
`added_post_meta`, `updated_post_meta` and `deleted_post_meta` for the
storage keys of the page builders (each adapter lists its own, filter
`SAMOMIND/sync/builder_meta_keys`); and, as extras nothing depends on,
`elementor/document/after_save` and `fl_builder_after_save_layout`
(published layouts only). A queued item records its earliest send time,
the last enqueue plus 30 seconds (filter `SAMOMIND/sync/debounce_seconds`);
every new save of the post restarts the wait. A worker run that happens
earlier, for any reason, leaves the item alone and reschedules itself for
the first due item. Several saves in a row therefore become one push of the
final content. Full sync, reconcile pushes and settings changes (untick,
tick again) are not delayed.
Why: builders write their layout after the post itself is saved
(Elementor's `Document::save()` calls `wp_update_post()` first and
`update_metadata( '_elementor_data' )` afterwards), so a push triggered by
the status hook alone could read the old layout, and nothing re-queued it.

**A9. Tooling.** Composer (dev only): PHPCS with WordPress Coding
Standards and PHPCompatibilityWP, PHPUnit 9.6, Brain Monkey. Unit tests
run without WordPress. CI on GitHub Actions: PHPCS, PHP 7.4 compatibility,
unit tests on PHP 7.4, 8.1, 8.3. `composer smoke` runs a wp-env smoke test
(activate, open both admin pages, `wp samomind status`) where Docker is
available. The ruleset is WordPress with these exclusions, each commented:
no file docblock (the reference documents classes), the reference's
`PREFIX/area/name` hook names, its `###` banner comments, the uppercase
`SAMOMIND()` accessor, and unused `$args` in WP-CLI commands.
`composer plugin-check` runs the static phpcs checks of the WordPress.org
Plugin Check tool (`phpcs-plugin-check.xml.dist`). Plugin Check is not on
Packagist, so `composer.json` pins its 2.1.0 GitHub release as a `package`
repository; only its `phpcs-sniffs` and `phpcs-rulesets` are used. The
tool's VIP query check (`WordPressVIPMinimum.Performance.WPQueryParams`) is
left out to avoid a second dependency. `composer build`
(`bin/build-zip.sh`) packs HEAD into `build/samomind-ai-assistant-<version>.zip`
without the paths in `.distignore`, and refuses when the header `Version`
and readme `Stable tag` differ; the Release workflow attaches that zip to
a GitHub release on a `v*` tag.
Why: the reference has no tests or tooling.

**A10. Page builder adapters.** `SAMOMIND_Builders` picks one adapter per
post by its stored data, in this order: Elementor, Beaver Builder, Bricks,
Oxygen, Divi (4 and 5), WPBakery, Avada / Fusion Builder, then core (block
and Classic editor, which claims everything else). Each adapter implements
`SAMOMIND_Builder_Adapter`: `detect()` reads the post's meta or content,
`is_active()` says whether the builder is loaded now, `render()` returns
HTML (or null when there is no public renderer), `meta_keys()` lists the
keys its saves write, `fallback_source()` names the stored text the
fallback uses. `extract()` runs it, and nothing it does can fatal:

1. the builder is not loaded: reason `builder_inactive`, fallback;
2. `render()` throws (any `Throwable`): reason `adapter_failed`, fallback;
3. `render()` returns null: reason `no_renderer`, fallback;
4. it returns an empty string for a builder page: fallback.

The fallback removes shortcode tags (any tag, registered or not, with its
attributes; `[[escaped]]` and ordinary bracketed words stay), keeps the
text between them, and `to_text()` strips the HTML and collapses
whitespace. A rendered result that still shows shortcode tokens gets the
same treatment and the reason `unrendered_shortcodes`. Raw shortcodes are
never sent.

Quality check, when the document is built: a page is listed as "may be
incomplete" (Status page, `samomind_incomplete`) when its reasons include
`adapter_failed`, `builder_inactive` or `unrendered_shortcodes`, or when
the stored content is 300 characters or more (the larger of `post_content`,
the fallback source and the builder's meta) and the text that came out is
under 40 characters (`little_text`). `no_renderer` alone does not list a
page: it is the designed path for Bricks and Oxygen. The list changes only
when a post's reasons change, so a failure is recorded once per post, and a
post leaves it when a later build is clean or it is deleted. The filter
`SAMOMIND/content/text` receives the final text, the post and the
collected data, and its result is what is sent (cut to the API's limit);
the quality check runs after it.

What each adapter rests on (checked 2026-11-05):

| Builder | Detection and keys | Renderer | How verified |
|---|---|---|---|
| Core (block, Classic) | none, claims the rest | `apply_filters( 'the_content' )` with the global post set | WordPress core behaviour |
| Elementor | `_elementor_edit_mode` = `builder`; layout in `_elementor_data` | `Plugin::$instance->frontend->get_builder_content( $post_id )`, as the front end does on `the_content` | read in Elementor's source (GitHub `main`): `core/base/document.php`, `includes/frontend.php`; `Document::save()` writes the post, then the meta |
| Beaver Builder | `_fl_builder_enabled`; published layout `_fl_builder_data` (a draft is `_fl_builder_draft`, ignored) | `FLBuilder::render_content_by_id( $post_id )`, which echoes its output (captured with a buffer) | read in the wordpress.org Lite source (`classes/class-fl-builder.php`, `class-fl-builder-model.php`) |
| Bricks | `_bricks_page_content_2` (older `_bricks_page_content`), `_bricks_editor_mode` | none: Bricks' Academy documents a render filter, not a function that renders a post | key from the Bricks forum and third-party docs; closed source, not verified |
| Oxygen (classic) | `ct_builder_shortcodes`, `ct_builder_json`; `_ct_builder_*` since 4.8.3 | none; the fallback reads the shortcode meta | keys from Oxygen documentation as quoted by third parties; closed source, not verified. Oxygen 6 is not detected |
| Divi 4 | `_et_pb_use_builder` = `on`, shortcodes in `post_content` | the_content, only when `et_pb_section` is registered; else fallback | meta key and format from Divi documentation; closed source, whether the shortcodes load under WP-Cron is not verified |
| Divi 5 | same meta; `<!-- wp:divi/... {JSON} -->` blocks in `post_content` | the_content when `divi/section` is registered; else the text under each block's `innerContent` is read from the JSON | format from third-party Divi 5 documentation; not verified against Divi's code |
| WPBakery | `[vc_row` in `post_content`, `_wpb_vc_js_status` = `true` | `WPBMap::addAllMappedShortcodes()` when `vc_row` is missing, then the_content | the call is the one WPBakery support threads give for AJAX and similar requests; closed source, not verified |
| Avada / Fusion Builder | `fusion_builder_status` = `active`, `[fusion_builder_container` | the_content when the `fusion_*` shortcodes are registered; else fallback | closed source, not verified |

Why: a page builder keeps its content in its own storage and renders it
with its own code, so reading `post_content` alone sent empty or raw pages.

**A11. Status page.** Loading the Status page makes a lightweight
`/checkin` at most once a minute (`SAMOMIND_Connection::refresh_status()`),
with a fresh key and without moving the daily check-in time, so a
disconnect made in the SamoMind dashboard or a deleted source shows within
a page load. It never calls `/connect`, and it is skipped when a bad key
paused the site. The page also shows "Included on this site": published,
unprotected posts of the ticked types after filters, less the ids listed
by `SAMOMIND/content/excluded_ids` (hidden WooCommerce products are not
subtracted); it is separate from the last full sync's counts.

**A12. Ticking a post type again, and what reconcile reports.** Ticking a
type on the settings page queues pushes for its published, unprotected
items at once (replacing any delete still queued for them), through the
same worker and batching as any push. Reconcile does not compute "missing"
on the site: it sends the inventory (every eligible id with its modified
time, in byte order, in pages of the server's entry limit) and SamoMind
answers with `missing` (listed ids it has no live item for, which includes
items it deleted) and `stale` (listed with a newer modified time than it
holds). The plugin queues a push for each, replacing a delete still queued
for the same id, and the push turns back into a delete at send time if the
post stopped being eligible meanwhile.

**A13. Chat widget.** The widget is a setting, not a pasted script: security
scanners (Wordfence, Sucuri) flagged the pasted inline snippet. With
`samomind_widget_enabled` on and a valid `samomind_site_key`,
`SAMOMIND_Embed::enqueue_widget()` calls `wp_enqueue_script()` for
`https://widget.samomind.com/widget.js` (handle `samomind-widget`, footer,
`defer` strategy, no version). The loader reads `data-site-key` and
`data-base-url` from its own tag (SamoMind `widget/loader/src/config.js`), so
`add_widget_attributes()` adds both through `wp_script_attributes`, only for
the tag whose id is `samomind-widget-js`. The base URL is the configured API
base URL. Nothing is echoed. It never loads in wp-admin, AJAX, the login
page, feeds, embeds, previews, the Customizer or REST/JSON requests.
The contract has no endpoint that returns the site key (`/connect` and
`/checkin` answer with `source_id`, interval, limits, state), and the
ingest key is not a site key, so the key is typed in and validated against
`pk_live_` + 20 to 128 URL-safe characters (what SamoMind's
`generate_site_key()` produces); the value is checked as typed, never
stripped of markup first. The key's origin must be in the dashboard's
Allowed origins; the settings page shows the exact origin from
`home_url()`. Optimisers: the script is excluded from WP Rocket
(`rocket_exclude_js`, `rocket_exclude_defer_js`, `rocket_delay_js_exclusions`),
LiteSpeed Cache (`litespeed_optimize_js_excludes`, `litespeed_optm_js_defer_exc`)
and Autoptimize (`autoptimize_filter_js_exclude`), and the tag carries
`data-no-optimize`, `data-noptimize` and `data-cfasync="false"`. The
LiteSpeed and Autoptimize hooks were checked in their source; the WP Rocket
names are from its documentation and not verified (its source is private).
`wp_script_attributes` reaches enqueued scripts from WordPress 6.4, so the
plugin requires 6.4. A site that pasted the 0.2.x snippet is migrated once
on `init`: the key is read from the snippet, the widget is turned on and the
old option is deleted.

SiteGround Speed Optimizer (`sg-cachepress` 7.8.6, read in source,
7.6.f). Its JavaScript features are Combine, Minify and Defer
(`siteground_optimizer_combine_javascript`, `_optimize_javascript`,
`_optimize_javascript_async`); it has no delay or load-on-interaction
feature. Combine copied our `widget.js` into
`siteground-optimizer-combined-js-<hash>.js` and removed our tag, which lost
the data attributes (found on softwareske.com, 0.3.0). Hooks:

| Hook | File:line | Used |
|---|---|---|
| `sgo_javascript_combine_exclude_ids` | `core/Combinator/Js_Combinator.php:741` | yes, adds `samomind-widget-js` (the tag id, checked per tag in `parse()` before the script is read) |
| `sgo_javascript_combine_excluded_external_paths` | `core/Combinator/Js_Combinator.php:894` | yes, adds `widget.samomind.com/widget.js` (substring of the src; this is the only list `is_excluded()` reads for an external script) |
| `sgo_js_async_exclude` | `core/Front_End_Optimization/Front_End_Optimization.php:226` | yes, adds the handle `samomind-widget`; without it Speed Optimizer adds a second `defer` (harmless) |
| `sgo_javascript_combine_exclude` (handles) | `core/Combinator/Js_Combinator.php:646` | no: the handles become `excluded_urls`, which only the internal branch of `is_excluded()` (line 903) reads, so it does nothing for an external script |
| `sgo_js_minify_exclude` (handles) | `core/Minifier/Minifier.php:145` | no: `minify_scripts()` skips every external source (the host check just below it), so Minify never touches the widget |

Each filter adds to the list it receives. Verified in wp-env (WordPress
7.1.4, Speed Optimizer 7.8.6, Combine, Minify and Defer on, logged-out
request, jQuery enqueued so the combiner has work): with the exclusions the
page has one `<script id="samomind-widget-js" defer ... data-site-key
data-base-url>` and the combined file holds no widget code; with the three
entries removed again (priority 999) the tag is gone and the combined file
is 3.7 KB bigger and contains the loader's `data-site-key` string. Speed
Optimizer's front-end features ran in wp-env without SiteGround hosting.
Verified on softwareske.com: not yet.
More optimisers (7.6.g). Every hook below was found in the plugin's
source (free plugins) or its official documentation (paid plugins).
"Run" means checked in wp-env with the plugin's JavaScript features on,
logged-out request, jQuery enqueued so there is work to do.

| Plugin | Version | Hook or attribute | Where | Status |
|---|---|---|---|---|
| Jetpack Boost | 4.8.2 | attribute `data-jetpack-boost="ignore"` (added in `add_widget_attributes()`) | `app/modules/optimizations/render-blocking-js/class-render-blocking-js.php:65` (value), `:91` (attribute name, filterable), `:462` (the lookahead that skips such tags). Its concatenate step already skips external URLs. | run. Red-proof: without the attribute Boost keeps one intact tag but moves it to the end of the page; the attributes are not lost. `jetpack_boost_render_blocking_js_exclude_handles` is not used: the plugin only registers a callback for it (`compatibility/jetpack.php:45`) and never applies it. |
| Hummingbird | 4.0.0 | filter `wphb_delay_js_exclusions` (array, each entry is a pattern on the whole tag and, in minify, a quoted URL part, so the URL text works for both) | `core/modules/class-exclusions.php:636`; used at `core/modules/class-delayjs.php:342-358` | run, with membership stubbed (Delay JS needs a WPMU DEV membership, `core/class-utils.php:77`). Red-proof: without the entry the tag gets `type="wphb-delay-type"` and waits for user input. |
| Hummingbird | 4.0.0 | filter `wphb_dont_combine_handles` (`$dont_combine, $handle, $type`: a yes/no answer, so we return true for our handle and keep an earlier true) | `core/modules/class-minify.php:1472` | unit-tested only. Its combine step reads files from disk, so an external script is not a case I could produce. |
| Perfmatters | not checked | filters `perfmatters_delay_js_exclusions`, `perfmatters_defer_js_exclusions`, `perfmatters_minify_js_exclusions` (arrays) | https://perfmatters.io/docs/filters/ | documented, not run. The docs do not say what an entry is matched against; their examples are file names and fragments, so we add the URL. |
| FlyingPress | not checked | filters `flying_press_exclude_from_minify:js`, `flying_press_exclude_from_defer:js`, `flying_press_exclude_from_delay:js` (each an array of exclude keywords; we append the URL) | minify: https://docs.flyingpress.com/en/articles/11405981-exclude-files-from-javascript-minification (read, matched as a partial, case-sensitive part of the URL). Defer and delay: https://docs.flyingpress.com/en/article/exclude-files-from-javascript-defer-and-delay-1uywyjq (names given by Felix from the official docs; that URL returned 404 when fetched on 2026-11-07, so the page was not read by us). | Documented, not run |
| NitroPack | 1.20.4 | bare attribute `nitro-exclude` (`'nitro-exclude' => true` in `wp_script_attributes`) | the connector plugin puts it on its own tags: `functions.php:299`, `classes/WordPress/Scripts.php:74`, and builds one from an array the same way at `classes/Feature/AjaxShortcodes.php:170`. NitroPack optimises on its own servers, so what its service does with it is from third-party guides only. | documented in the connector's own use, not run (SaaS) |
| W3 Total Cache | 2.11.0 | none needed | `Minify_Plugin.php:1428-1464`: an external script is only touched when it matches `minify.cache.files`, which is empty by default | run: minify and combine on, the page's local scripts went into three bundles with no widget code, our tag untouched, no delay. A site that lists the widget in `minify.cache.files` has to remove it. |

Why: the reference has no front-end script.

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
dashboard wins (contract 5.4 for `source_disconnected`; see "Still differs"
for `unknown_source`): the source id, queue and sync state are dropped, the
connection is blocked ("Disconnected from the SamoMind dashboard."), the
worker and the hourly tick send nothing, and `/connect` is called only from
the Connect button or `wp samomind connect`; `duplicate_request` counts as applied; `invalid_key`, `key_revoked`,
`unsupported_api_version`, `tenant_suspended` and `source_disabled` block
background work until settings change or a check-in succeeds; other 4xx
drop the request and record the error.

## 7. Hooks and filters for site owners

| Filter | Default | Use |
|---|---|---|
| `SAMOMIND/content/post_types` | post, page, public custom post types, `product` with WooCommerce, minus the types unticked on the settings page | add or remove post types; runs after the setting |
| `SAMOMIND/content/excluded_ids` | `array()` | post ids never sent |
| `SAMOMIND/content/text` | the extracted plain text | replace the text sent for one post (receives the text, the `WP_Post`, the collected data) |
| `SAMOMIND/sync/debounce_seconds` | `30` | seconds a saved post waits before it is pushed |
| `SAMOMIND/sync/builder_meta_keys` | the built-in builders' keys | add the storage meta key of another builder |
| `SAMOMIND/settings/get_plugin_name` | `SamoMind AI Assistant` | same as the reference |

Action: `SAMOMIND/plugin_loaded` after setup.

## 8. Where the contract decided

The 7.6.b brief and the contract differ in four places; the contract wins.

| Brief | Contract | Built |
|---|---|---|
| `external_id` = post type + ":" + post id | 9: "`external_id` is the post id as a string" | post id as a string |
| hourly check-in | 5.5 and 9: `checkin_interval_seconds` 86400, "check-in then reconcile daily" | hourly tick that checks in once the server's interval has passed, then reconciles |
| uninstall does not call disconnect | 9: deactivation does nothing server-side; only an explicit disconnect or uninstall calls `/disconnect` | uninstall disconnects when a source id is stored; deactivation sends nothing |
| status shows the document count reported by the API | 11: a `/checkin` indexed count is an open item; until then a connector shows its own count of documents sent | the status page shows the plugin's own counts from the last full sync and says the dashboard holds the indexed count |

Product price goes in `fields.price` (contract 4), not in `text`; terms of
public taxonomies go in `fields.categories`.

### Still differs

The contract copy is SamoMind `ba44704` (7.6.a1 amendments: deactivation
does nothing server-side, a dashboard disconnect wins, open item for an
indexed count). Two places still read differently from what is built:

| Contract | Built |
|---|---|
| 5.4: on `unknown_source`, "call `connect` again" | treated like `source_disconnected`: the plugin forgets the source and waits for the Connect button or `wp samomind connect`, so it never reconnects on its own |
| 9: `kind` `custom` is "a public custom post type the admin selected" | every public custom post type is sent until the admin unticks it on the settings page (`samomind_excluded_post_types`, section 4) |
