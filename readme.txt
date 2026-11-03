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

Use the `SAMOMIND/content/post_types` and `SAMOMIND/content/excluded_ids` filters to leave out post types or single posts.

== External services ==

This plugin connects to the SamoMind API, a service of SoftwaresKe Ltd, to keep your SamoMind assistant's knowledge base in sync. Nothing is sent until an administrator enters an ingest key and clicks Connect.

* Where: `https://api.samomind.com/api/v1/ingest/...` by default, or the API base URL an administrator sets.
* What and when: on Connect, the site address, "wordpress", and the plugin and WordPress versions; on Connect and "Sync now", the public content described above, in batches; when public content is published, changed or removed, that item or its id; once a day, a check-in (plugin and WordPress versions, last error) and the list of public item ids with their modified times; on Disconnect, deactivation or uninstall, a disconnect request.
* Every request is signed with your ingest key. The secret itself is never sent.

If you paste the chat widget snippet, visitors' browsers load the widget from `https://widget.samomind.com`, and the widget talks to the SamoMind API.

Service: https://samomind.com

No other outbound requests are made. There is no telemetry.

== Installation ==

1. Upload the `samomind-ai-assistant` folder to `/wp-content/plugins/`, or install the zip from Plugins > Add New > Upload Plugin.
2. Activate the plugin.
3. In the SamoMind dashboard, open Sources, then the WordPress card, and click "Create an ingest key". Copy the key id and the secret (the secret is shown only once).
4. In WordPress, open SamoMind > Settings. Save the secret, then the key id. You can define `SAMOMIND_API_SECRET` in `wp-config.php` instead of saving the secret.
5. Open SamoMind > Status and click Connect. The first sync starts in the background.
6. Optional: paste the widget snippet from the dashboard's Widget screen into SamoMind > Settings > Embed snippet.

== Frequently Asked Questions ==

= Where do I see what was sent? =

SamoMind > Status shows the sync progress and the last 200 requests (time, endpoint, HTTP status and error code). Request bodies and credentials are never logged.

= What happens when I deactivate or delete the plugin? =

Deactivating disconnects the site: SamoMind removes the documents it received from this site. Your settings stay, so you can reconnect later. Deleting the plugin removes all of its settings, logs and scheduled events; if the site was still connected (for example because the disconnect at deactivation failed), it disconnects first.

= My site is quiet and updates arrive late. =

WP-Cron runs when the site gets visits. Set up a real cron job that runs `wp cron event run --due-now`, or install WooCommerce, whose Action Scheduler runs the work.

= Sync stopped with plan_page_cap. =

Your SamoMind plan's page limit is reached. Upgrade the plan or remove pages in the SamoMind dashboard, then click "Sync now".

== Changelog ==

= 0.1.0 =
* First release: connect, full sync, event-driven updates and deletes, daily check-in and reconcile, request log, widget embed, WP-CLI commands.

== Upgrade Notice ==

= 0.1.0 =
First release.
