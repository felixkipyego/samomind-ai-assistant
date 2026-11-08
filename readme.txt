=== SamoMind AI Assistant ===
Contributors: samomind, kipyegofelix
Tags: ai assistant, chatbot, knowledge base, woocommerce, sync
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.3.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Keeps your SamoMind AI assistant's knowledge base in sync with this site's public pages, posts and products, and adds the chat widget.

== Description ==

Visitors ask the same questions again and again: what do you charge, where are you, how do I book. SamoMind AI Assistant lets your site answer them for you, from your own pages, and links to the page each answer came from.

The plugin connects this site to your SamoMind account. Your public pages, posts and products are pushed to your SamoMind knowledge base and kept up to date as you edit, so the assistant never answers from stale content. You can then show the SamoMind chat widget on your site with one switch and a site key. A SamoMind account is needed. Accounts are created at https://app.samomind.com, and access is currently by invitation; ask support@samomind.com (see the FAQ).

* Full sync on connect, then updates as you publish, edit, trash or unpublish.
* Pages, posts and WooCommerce products by default; public custom post types if you tick them.
* Daily check-in and reconcile, so SamoMind and the site stay in step.
* Runs in the background on Action Scheduler (with WooCommerce) or WP-Cron.
* Optional: adds the SamoMind chat widget to every page.
* WP-CLI: `wp samomind status|connect|disconnect|sync|reconcile|checkin`.

= What is sent =

Only published, public content that is not password-protected: for each page, post, public custom post type item or WooCommerce product, its id, address, title, text (the rendered content as plain text, the excerpt and, for products, the short description), type, last modified time and the names of its public categories and tags. Products add price and stock status.

Never sent: drafts, scheduled, private or password-protected content, users, visitors, comments, form entries, orders, carts, customers or media files. Hidden WooCommerce products are not sent.

Choose the post types under SamoMind > Settings > What is synced (posts, pages and WooCommerce products are ticked by default, other types start unticked). Unticking a type removes its items from SamoMind. Developers can also use the `SAMOMIND/content/post_types` filter (it runs after that setting) and `SAMOMIND/content/excluded_ids` to leave out single posts.

= External services =

This plugin is the connector for SamoMind, a hosted service of SoftwaresKe Ltd. It talks to two SamoMind hosts: the SamoMind API (`https://api.samomind.com`, or the API base URL an administrator sets) and, only if the chat widget is switched on, the widget host (`https://widget.samomind.com`).

Service: https://samomind.com
Terms of service: https://samomind.com/terms-and-conditions/
Privacy policy: https://samomind.com/privacy-policy/

**1. The SamoMind API, from the server**

Nothing is sent when the plugin is installed or activated. Requests start only after an administrator saves an ingest key and clicks Connect (or runs `wp samomind connect`). Every request is a signed POST to `/api/v1/ingest/...`. Each carries the API version, the key id, a timestamp, a request id and an HMAC signature in headers; the secret itself is never sent. WordPress adds its standard User-Agent, which names the WordPress version and this site's address, and SamoMind sees the server's IP address.

* Connect, Reconnect (`/connect`): the site address, the word "wordpress", the plugin version and the WordPress version. Returns an id for this connection, the service's limits and the check-in interval. A first sync follows.
* Check-in (`/checkin`): the connection id, the plugin version, the WordPress version and, if the last request failed, that error's code and message (up to 500 characters). When: about once a day from WP-Cron, when an administrator clicks "Check in now" or opens SamoMind > Status (at most once a minute), or runs `wp samomind checkin`. Returns the connection status and how often to check in.
* Sync (`/sync` and `/documents`): when a sync starts and finishes, and in batches in between, for each published, public, not password-protected item of the post types ticked in Settings: its post id, address, title, text (the content as plain text, the excerpt and, for products, the short description), type (post, page, product or custom), last modified time and the names of its public categories and tags. Products add price and stock status. When: on Connect, on "Sync now" and `wp samomind sync`. Returns, per item, whether it was accepted, unchanged or refused.
* Changes (`/documents` and `/documents/delete`): when an item is published, edited, unpublished, trashed or deleted, or when a post type is ticked or unticked. The first sends that item as above; the second sends only post ids. Sent about 30 seconds after the last save.
* Reconcile (`/reconcile`): the post id and last modified time of every included item, in pages, with the id range. When: daily after the check-in, "Reconcile now", `wp samomind reconcile`. Returns the ids SamoMind lacks, holds out of date or has removed, which the plugin then sends or ignores.
* Disconnect (`/disconnect`): the connection id and the reason, "admin" or "uninstall". When: an administrator clicks Disconnect, or the plugin is deleted while connected. Deactivating sends nothing.

Never sent: drafts, scheduled, private or password-protected content, users, comments, form entries, orders, carts, customers, media files, the WordPress admin's data, or the list of other plugins.

**2. The SamoMind chat widget, from your visitors' browsers**

Only if an administrator ticks "Show the SamoMind chat widget on this site" and saves a site key. The plugin then adds one script tag to the pages visitors see (never the admin, login page, feeds or REST responses). The widget is off by default. No widget request of any kind happens unless an administrator switches the widget on; with it off, no page loads anything from SamoMind.

* On every page view: the visitor's browser requests `widget.js` and `widget.css` from `https://widget.samomind.com`, and asks `https://api.samomind.com/api/v1/widget-appearance` for this site's colours and label, passing the site key. SamoMind therefore sees the visitor's IP address, browser details and the address of the page.
* When a visitor opens the chat: the browser loads the chat script `chat.js` from `https://widget.samomind.com`. This is the service's own code, not a copy of a third-party library.
* When a visitor chats: the browser sends the site key and a random visitor id to `/api/v1/session`, then each message the visitor types, with the conversation id, to `/api/v1/chat`, and reads the answer with its sources. If the visitor opens their history or asks for a human, the browser also requests their earlier conversations, or sends the name, email address or phone number they type into that form. The random visitor id and the conversation id are kept in the visitor's browser (local storage) so the conversation continues; no cookie is set.

Like any web request, the visitor's browser requests to `widget.samomind.com` and `api.samomind.com` reach SamoMind's servers with the visitor's IP address and browser user agent. See the privacy policy: https://samomind.com/privacy-policy/

No other outbound requests are made. There is no telemetry and no advertising. The plugin adds no "powered by" link of its own to your site.

== Installation ==

1. Upload the `samomind-ai-assistant` folder to `/wp-content/plugins/`, or install the zip from Plugins > Add New > Upload Plugin.
2. Activate the plugin.
3. In the SamoMind dashboard, open Sources, then the WordPress card, and click "Create an ingest key". Copy the key id and the secret (the secret is shown only once).
4. In WordPress, open SamoMind > Settings. Save the secret, then the key id. You can define `SAMOMIND_API_SECRET` in `wp-config.php` instead of saving the secret.
5. Open SamoMind > Status and click Connect. The first sync starts in the background.
6. Optional: to show the chat, add your site address to Allowed origins in the SamoMind dashboard, then in SamoMind > Settings > Chat widget tick "Show the SamoMind chat widget on this site" and enter the site key from the dashboard's Widget screen. There is nothing to paste into your theme or pages.

== Frequently Asked Questions ==

= Do I need a SamoMind account? =

Yes. The plugin is free, but it sends your content to your SamoMind knowledge base, so you need a SamoMind account and an ingest key from the dashboard (Sources, then the WordPress card). Accounts are created at https://app.samomind.com. Access is currently by invitation: write to support@samomind.com to ask for one. Your SamoMind plan sets how many pages the knowledge base holds; when the limit is reached the sync stops with `plan_page_cap` on SamoMind > Status. Upgrade the plan or remove pages in the dashboard, then click "Sync now".

= What exactly is sent to SamoMind? =

Only published, public content that is not password-protected, and only the post types ticked under SamoMind > Settings > What is synced. Drafts, private content, users, comments, orders and customers are never sent. See "What is sent" above for the full list. SamoMind > Status shows the last 200 requests (time, endpoint, HTTP status and error code); request bodies and credentials are never logged.

= Does it work with Elementor, Divi and other page builders? =

Yes. For each page the plugin finds the builder it was made with and asks that builder to render it, so the assistant gets the text visitors see. Elementor, Beaver Builder, Divi, WPBakery and Avada are supported, as are the block and Classic editors. Bricks and Oxygen keep their text in a form the plugin cannot render, so those pages may be sent with less text. If a builder fails or is switched off, the plugin sends the page's stored text instead and never sends raw shortcodes. SamoMind > Status lists any pages that may be incomplete and why; a developer can supply the text of a page with the `SAMOMIND/content/text` filter.

= How quickly do changes show up in the assistant? =

A change is sent about 30 seconds after the last save (so several quick edits become one update), then SamoMind indexes it. A daily check-in and reconcile catches anything missed. WP-Cron only runs when the site gets visits, so on a quiet site set up a real cron job that runs `wp cron event run --due-now`, or install WooCommerce, whose Action Scheduler runs the work.

= Will it slow down my site? =

No. All syncing runs in the background in small batches, never while a visitor loads a page. The only thing added to the front end is the chat widget script, and only if you turn it on under SamoMind > Settings > Chat widget. It loads deferred in the footer.

= The chat widget does not show. What do I check? =

The setting is on and a site key is saved; your site address (shown under the setting) is in Allowed origins in the SamoMind dashboard; and you are looking at a page on the front end, not the admin or a preview. The plugin keeps WP Rocket, LiteSpeed Cache, Autoptimize, SiteGround Speed Optimizer, Jetpack Boost, Hummingbird, Perfmatters and NitroPack from combining, minifying, deferring or delaying the widget script, and FlyingPress from minifying it. If you use FlyingPress, also add `widget.samomind.com` to its Delay JavaScript and Defer JavaScript exclusions. W3 Total Cache leaves the script alone unless you list it under its minify external files. With any other optimisation plugin, exclude `widget.samomind.com` from its JavaScript combine, minify, defer and delay options.

= What happens when I deactivate, delete or disconnect? =

Deactivating only stops the plugin's scheduled work. Nothing is sent, your documents stay in SamoMind and the connection is kept, so reactivating carries on without a full sync; changes made while it was inactive are picked up by the next daily reconcile. Deleting the plugin disconnects the site if it is still connected (SamoMind then removes the documents it received from this site) and removes all of the plugin's settings, logs and scheduled events. Disconnect on the Status page removes your content from SamoMind but keeps the plugin and its settings. If someone disconnects the site in the SamoMind dashboard, the dashboard wins: the plugin stops sending, shows "Disconnected from the SamoMind dashboard." on SamoMind > Status and does not reconnect until you click Connect.

== Screenshots ==

1. SamoMind > Settings: the ingest key, the content types that are synced and the chat widget switch with its site key.
2. The content types list and the sync status, with the number of items kept in step with SamoMind.
3. The chat widget's welcome screen on a live site, with suggested questions.
4. An answer from the chat widget, with a link to the source page.
5. The chat widget on a phone-sized screen.

== Changelog ==

= 0.3.4 =
* Escaping and autoload hardening for the WordPress.org review. Clearer External services section.

= 0.3.3 =
* New installs now sync only posts, pages and WooCommerce products by default; other content types can be ticked in Settings. Existing sites keep their current selection.

= 0.3.2 =
* Keeps the chat widget working with Jetpack Boost, Hummingbird, Perfmatters, FlyingPress and NitroPack optimisation.

= 0.3.1 =
* Keeps the chat widget working when SiteGround Speed Optimizer combines JavaScript files.

= 0.3.0 =
* The chat widget is now a setting: tick "Show the SamoMind chat widget on this site" and enter the site key. The plugin loads the widget script itself, so nothing is pasted into the site and security scanners no longer flag an inline script.
* Sites that had pasted the old embed snippet keep their widget: the key is moved to the new setting on the first page load after the update.
* The widget script is kept out of WP Rocket, LiteSpeed Cache and Autoptimize combine, minify and delay options.
* Requires WordPress 6.4.

= 0.2.0 =
* Edits now reach the assistant: saves from any editor or page builder are queued, sent 30 seconds after the last save, and read fresh when sent.
* Page builder support: Elementor, Beaver Builder, Divi, WPBakery, Avada; Bricks and Oxygen read from stored content. Pages that may be incomplete are listed on the Status page.
* Ticking a post type again sends its items at once.
* The Status page checks in when opened, so a disconnect made in the SamoMind dashboard shows immediately, and shows how many items are included on this site.

= 0.1.0 =
* First release: connect, full sync, event-driven updates and deletes, daily check-in and reconcile, request log, widget embed, WP-CLI commands.

== Upgrade Notice ==

= 0.3.4 =
Hardening for the WordPress.org review and a clearer External services section. No change in behaviour.

= 0.3.3 =
New installs start with posts, pages and products only. Your current selection is kept and nothing already sent is removed.

= 0.3.2 =
Keeps the chat widget working with more optimisation plugins. If you use FlyingPress, also add widget.samomind.com to its Delay JavaScript and Defer JavaScript exclusions.

= 0.3.1 =
Fixes the chat widget disappearing on sites where SiteGround Speed Optimizer combines JavaScript files.

= 0.3.0 =
The chat widget is now a setting under SamoMind > Settings > Chat widget. If you pasted the old snippet, it is converted for you. Requires WordPress 6.4.

= 0.2.0 =
Fixes edits that did not reach the assistant, adds page builder support and re-sends a post type when you tick it again.

= 0.1.0 =
First release.
