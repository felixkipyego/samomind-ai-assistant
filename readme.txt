=== SamoMind AI Assistant ===
Contributors: softwareske
Tags: ai assistant, chatbot, knowledge base, woocommerce, sync
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.3.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Keeps your SamoMind AI assistant's knowledge base in sync with this site's public pages, posts and products, and adds the chat widget.

== Description ==

SamoMind AI Assistant connects this site to your SamoMind account. Your public content is pushed to your SamoMind knowledge base and kept up to date, so the assistant answers from your current pages, with links to them.

* Full sync on connect, then updates as you publish, edit, trash or unpublish.
* Pages, posts, public custom post types and WooCommerce products.
* Daily check-in and reconcile, so SamoMind and the site stay in step.
* Runs in the background on Action Scheduler (with WooCommerce) or WP-Cron.
* Optional: adds the SamoMind chat widget to every page.
* WP-CLI: `wp samomind status|connect|disconnect|sync|reconcile|checkin`.

= What is sent =

Only published, public content that is not password-protected: for each page, post, public custom post type item or WooCommerce product, its id, address, title, text (the rendered content as plain text, the excerpt and, for products, the short description), type, last modified time and the names of its public categories and tags. Products add price and stock status.

Never sent: drafts, scheduled, private or password-protected content, users, visitors, comments, form entries, orders, carts, customers or media files. Hidden WooCommerce products are not sent.

Choose the post types under SamoMind > Settings > What is synced (all ticked by default). Unticking a type removes its items from SamoMind. Developers can also use the `SAMOMIND/content/post_types` filter (it runs after that setting) and `SAMOMIND/content/excluded_ids` to leave out single posts.

= External services =

This plugin connects to the SamoMind API, a service of SoftwaresKe Ltd, to keep your SamoMind assistant's knowledge base in sync. Nothing is sent until an administrator enters an ingest key and clicks Connect.

* Where: `https://api.samomind.com/api/v1/ingest/...` by default, or the API base URL an administrator sets.
* What and when: on Connect, the site address, "wordpress", and the plugin and WordPress versions; on Connect and "Sync now", the public content described above, in batches; when public content is published, changed or removed, that item or its id; once a day, a check-in (plugin and WordPress versions, last error) and the list of public item ids with their modified times; on Disconnect or uninstall, a disconnect request. Deactivating the plugin sends nothing.
* Every request is signed with your ingest key. The secret itself is never sent.

**SamoMind chat widget (`https://widget.samomind.com`)**

Only if an administrator turns on "Show the SamoMind chat widget on this site" and saves a site key. The plugin then loads one script, `https://widget.samomind.com/widget.js`, on the pages visitors see (never in the admin, the login page, feeds or REST responses).

* What it is: the SamoMind chat widget, served by SamoMind from `widget.samomind.com`.
* What is sent: every visitor's browser requests the script from `widget.samomind.com`, which sees the visitor's IP address, browser details and the page that requested it. When a visitor opens the chat, the widget sends the visitor's messages and the site key to the SamoMind API (`https://api.samomind.com` by default, or the API base URL set in the plugin).
* When: on each page view while the widget is on. With the setting off, nothing is loaded and nothing is sent.

Service: https://samomind.com
Terms of service: https://samomind.com/terms-and-conditions/
Privacy policy: https://samomind.com/privacy-policy/

No other outbound requests are made. There is no telemetry.

== Installation ==

1. Upload the `samomind-ai-assistant` folder to `/wp-content/plugins/`, or install the zip from Plugins > Add New > Upload Plugin.
2. Activate the plugin.
3. In the SamoMind dashboard, open Sources, then the WordPress card, and click "Create an ingest key". Copy the key id and the secret (the secret is shown only once).
4. In WordPress, open SamoMind > Settings. Save the secret, then the key id. You can define `SAMOMIND_API_SECRET` in `wp-config.php` instead of saving the secret.
5. Open SamoMind > Status and click Connect. The first sync starts in the background.
6. Optional: to show the chat, add your site address to Allowed origins in the SamoMind dashboard, then in SamoMind > Settings > Chat widget tick "Show the SamoMind chat widget on this site" and enter the site key from the dashboard's Widget screen. There is nothing to paste into your theme or pages.

== Frequently Asked Questions ==

= Do I need a SamoMind account? =

Yes. The plugin is free, but it sends your content to your SamoMind knowledge base, so you need a SamoMind account and an ingest key from the dashboard (Sources, then the WordPress card). Your SamoMind plan sets how many pages the knowledge base holds; when the limit is reached the sync stops with `plan_page_cap` on SamoMind > Status. Upgrade the plan or remove pages in the dashboard, then click "Sync now".

= What exactly is sent to SamoMind? =

Only published, public content that is not password-protected, and only the post types ticked under SamoMind > Settings > What is synced. Drafts, private content, users, comments, orders and customers are never sent. See "What is sent" above for the full list. SamoMind > Status shows the last 200 requests (time, endpoint, HTTP status and error code); request bodies and credentials are never logged.

= Does it work with Elementor, Divi and other page builders? =

Yes. For each page the plugin finds the builder it was made with and asks that builder to render it, so the assistant gets the text visitors see. Elementor, Beaver Builder, Divi, WPBakery and Avada are supported, as are the block and Classic editors. Bricks and Oxygen keep their text in a form the plugin cannot render, so those pages may be sent with less text. If a builder fails or is switched off, the plugin sends the page's stored text instead and never sends raw shortcodes. SamoMind > Status lists any pages that may be incomplete and why; a developer can supply the text of a page with the `SAMOMIND/content/text` filter.

= How quickly do changes show up in the assistant? =

A change is sent about 30 seconds after the last save (so several quick edits become one update), then SamoMind indexes it. A daily check-in and reconcile catches anything missed. WP-Cron only runs when the site gets visits, so on a quiet site set up a real cron job that runs `wp cron event run --due-now`, or install WooCommerce, whose Action Scheduler runs the work.

= Will it slow down my site? =

No. All syncing runs in the background in small batches, never while a visitor loads a page. The only thing added to the front end is the chat widget script, and only if you turn it on under SamoMind > Settings > Chat widget. It loads deferred in the footer.

= The chat widget does not show. What do I check? =

The setting is on and a site key is saved; your site address (shown under the setting) is in Allowed origins in the SamoMind dashboard; and you are looking at a page on the front end, not the admin or a preview. The plugin keeps WP Rocket, LiteSpeed Cache, Autoptimize and SiteGround Speed Optimizer from combining or minifying the widget script. With another optimisation plugin, exclude `widget.samomind.com` from its JavaScript combine, minify and delay options.

= What happens when I deactivate, delete or disconnect? =

Deactivating only stops the plugin's scheduled work. Nothing is sent, your documents stay in SamoMind and the connection is kept, so reactivating carries on without a full sync; changes made while it was inactive are picked up by the next daily reconcile. Deleting the plugin disconnects the site if it is still connected (SamoMind then removes the documents it received from this site) and removes all of the plugin's settings, logs and scheduled events. Disconnect on the Status page removes your content from SamoMind but keeps the plugin and its settings. If someone disconnects the site in the SamoMind dashboard, the dashboard wins: the plugin stops sending, shows "Disconnected from the SamoMind dashboard." on SamoMind > Status and does not reconnect until you click Connect.

== Screenshots ==

1. SamoMind > Status: connection, sync progress and the request log.
2. SamoMind > Settings: ingest key, the post types that are synced, and the chat widget switch and site key.

== Changelog ==

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

= 0.3.1 =
Fixes the chat widget disappearing on sites where SiteGround Speed Optimizer combines JavaScript files.

= 0.3.0 =
The chat widget is now a setting under SamoMind > Settings > Chat widget. If you pasted the old snippet, it is converted for you. Requires WordPress 6.4.

= 0.2.0 =
Fixes edits that did not reach the assistant, adds page builder support and re-sends a post type when you tick it again.

= 0.1.0 =
First release.
