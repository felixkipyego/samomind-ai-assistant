=== SamoMind AI Assistant ===
Contributors: softwareske
Tags: ai assistant, chatbot, knowledge base, woocommerce, sync
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
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

If you paste the chat widget snippet, visitors' browsers load the widget from `https://widget.samomind.com`, and the widget talks to the SamoMind API.

Service: https://samomind.com
<!-- TODO(7.6.d): Terms of service URL. No terms page exists yet; add the real link here before the WordPress.org submission. -->
<!-- TODO(7.6.d): Privacy policy URL. No privacy page exists yet; add the real link here before the WordPress.org submission. -->

No other outbound requests are made. There is no telemetry.

== Installation ==

1. Upload the `samomind-ai-assistant` folder to `/wp-content/plugins/`, or install the zip from Plugins > Add New > Upload Plugin.
2. Activate the plugin.
3. In the SamoMind dashboard, open Sources, then the WordPress card, and click "Create an ingest key". Copy the key id and the secret (the secret is shown only once).
4. In WordPress, open SamoMind > Settings. Save the secret, then the key id. You can define `SAMOMIND_API_SECRET` in `wp-config.php` instead of saving the secret.
5. Open SamoMind > Status and click Connect. The first sync starts in the background.
6. Optional: paste the widget snippet from the dashboard's Widget screen into SamoMind > Settings > Embed snippet.

== Frequently Asked Questions ==

= Do I need a SamoMind account? =

Yes. The plugin is free, but it sends your content to your SamoMind knowledge base, so you need a SamoMind account and an ingest key from the dashboard (Sources, then the WordPress card). Your SamoMind plan sets how many pages the knowledge base holds; when the limit is reached the sync stops with `plan_page_cap` on SamoMind > Status. Upgrade the plan or remove pages in the dashboard, then click "Sync now".

= What exactly is sent to SamoMind? =

Only published, public content that is not password-protected, and only the post types ticked under SamoMind > Settings > What is synced. Drafts, private content, users, comments, orders and customers are never sent. See "What is sent" above for the full list. SamoMind > Status shows the last 200 requests (time, endpoint, HTTP status and error code); request bodies and credentials are never logged.

= How quickly do changes show up in the assistant? =

A change is sent within a minute or so of publishing, editing, trashing or unpublishing it, then SamoMind indexes it. A daily check-in and reconcile catches anything missed. WP-Cron only runs when the site gets visits, so on a quiet site set up a real cron job that runs `wp cron event run --due-now`, or install WooCommerce, whose Action Scheduler runs the work.

= Will it slow down my site? =

No. All syncing runs in the background in small batches, never while a visitor loads a page. The only thing added to the front end is the chat widget, and only if you paste its snippet.

= What happens when I deactivate, delete or disconnect? =

Deactivating only stops the plugin's scheduled work. Nothing is sent, your documents stay in SamoMind and the connection is kept, so reactivating carries on without a full sync; changes made while it was inactive are picked up by the next daily reconcile. Deleting the plugin disconnects the site if it is still connected (SamoMind then removes the documents it received from this site) and removes all of the plugin's settings, logs and scheduled events. Disconnect on the Status page removes your content from SamoMind but keeps the plugin and its settings. If someone disconnects the site in the SamoMind dashboard, the dashboard wins: the plugin stops sending, shows "Disconnected from the SamoMind dashboard." on SamoMind > Status and does not reconnect until you click Connect.

== Screenshots ==

1. SamoMind > Status: connection, sync progress and the request log.
2. SamoMind > Settings: ingest key, the post types that are synced, and the widget embed snippet.

== Changelog ==

= 0.1.0 =
* First release: connect, full sync, event-driven updates and deletes, daily check-in and reconcile, request log, widget embed, WP-CLI commands.

== Upgrade Notice ==

= 0.1.0 =
First release.
