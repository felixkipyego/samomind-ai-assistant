=== Samomind – AI Chatbot That Answers From Your Content ===
Contributors: samomind, kipyegofelix
Tags: ai chatbot, chatbot, woocommerce, order tracking, customer support
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.4.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Keeps your Samomind AI chatbot in sync with your site, adds the chat widget with product cards, and lets shoppers check their WooCommerce orders.

== Description ==

Visitors ask the same questions again and again: what do you charge, where are you, how do I book. Samomind lets your site answer them for you, from your own pages, and links to the page each answer came from.

Running a WooCommerce shop? The chat can show your products as cards with a photo and the price, and your customers can ask "Where is my order?" and see their own orders in the chat, after proving they are the buyer.

The plugin connects this site to your Samomind account. Your public pages, posts and products are pushed to your Samomind knowledge base and kept up to date as you edit, so the assistant never answers from stale content. You can then show the Samomind chat widget on your site with one switch. A Samomind account is needed. Create one at https://app.samomind.com; it starts with a 7-day free trial, and plans are on https://samomind.com/pricing/.

* Connect with a one-time code from your Samomind dashboard: nothing to copy by hand.
* Full sync on connect, then updates as you publish, edit, trash or unpublish.
* Pages, posts and WooCommerce products by default; public custom post types if you tick them.
* Daily check-in and reconcile, so Samomind and the site stay in step.
* Runs in the background on Action Scheduler (with WooCommerce) or WP-Cron.
* Optional: adds the Samomind chat widget to every page. Answers about products can show product cards with a photo and the price.
* Optional, for WooCommerce 8.2 or newer: customers check the status of their own orders, and see all their orders, in the chat. Off until you switch it on.
* WP-CLI: `wp samomind status|connect|disconnect|sync|reconcile|checkin`.

= Order status in the chat =

Order status is part of the Samomind service for shops running WooCommerce 8.2 or newer. A customer asks the assistant where their order is, and proves who they are in one of two ways: a 6-digit code that your shop emails to them, or by being signed in to your shop. After the code, they can see the orders placed with that email address; signed-in customers see the orders of their account. The assistant shows the order number, status, dates and order total, and, if you choose, the item names with their product photos and the tracking details.

It is off until you switch it on under Samomind > Settings > Order status in the chat, accept the order-data terms and paste an orders key from your Samomind dashboard (Orders > Setup). You can switch it off again at any time on the same page. It works with WooCommerce's order tables (HPOS) on or off. The settings are always shown on that page; if something is missing (WooCommerce, pretty permalinks), the page says what.

= What is sent =

Only published, public content that is not password-protected: for each page, post, public custom post type item or WooCommerce product, its id, address, title, text (the rendered content as plain text, the excerpt and, for products, the short description), type, last modified time and the names of its public categories and tags. Products add the price as the shop shows it to visitors (for example "$70.00 (was $100.00)" or "$30.00 to $50.00"), the stock status, and, for the product cards in the chat, the address of the main product image and the price text (and the regular price of a simple or external product on sale).

Never sent by the content sync: drafts, scheduled, private or password-protected content, users, visitors, comments, form entries, orders, carts, customers or media files. Order status is separate: it reads orders only when a customer has proved who they are, and only those of that customer (see External services). WooCommerce products whose catalog visibility is Hidden are never sent; products set to Shop only or Search results only are, because their pages are public.

Choose the post types under Samomind > Settings > What is synced (posts, pages and WooCommerce products are ticked by default, other types start unticked). Unticking a type removes its items from Samomind. Developers can also use the `SAMOMIND/content/post_types` filter (it runs after that setting) and `SAMOMIND/content/excluded_ids` to leave out single posts.

= External services =

This plugin is the connector for Samomind, a hosted service of SoftwaresKe Ltd. It talks to two Samomind hosts: the Samomind API (`https://api.samomind.com`, or the API base URL an administrator sets) and, only if the chat widget is switched on, the widget host (`https://widget.samomind.com`).

Service: https://samomind.com
Terms of service: https://samomind.com/terms-and-conditions/
Privacy policy: https://samomind.com/privacy-policy/

**1. The Samomind API, from the server**

Nothing is sent when the plugin is installed or activated. Requests start only after an administrator saves an ingest key and clicks Connect (or runs `wp samomind connect`), or pastes a connection code and clicks Connect. Every request below except the connection code is a signed POST to `/api/v1/ingest/...`. Each carries the API version, the key id, a timestamp, a request id and an HMAC signature in headers; the secret itself is never sent. WordPress adds its standard User-Agent, which names the WordPress version and this site's address, and Samomind sees the server's IP address.

* Connect with a code (`/api/v1/plugin/connect`): an unsigned HTTPS POST that carries the connection code the owner pasted, the site's home address and site address, the plugin version and the WordPress version. When: only when an administrator pastes a code under Samomind > Settings and clicks Connect, never in the background. Why: to trade the one-time code for this site's keys, so the owner does not have to copy three values by hand. Returns the public site key (for the chat widget), an ingest key id, a new ingest secret and the API address, which the plugin saves in its own settings (the secret is stored like a password, kept out of autoload and never shown again). The code is never saved or logged. The plugin sends it only to an https address (plain http only for localhost), checks the server's certificate and does not follow redirects.
* Connect, Reconnect (`/connect`): the site address, the word "wordpress", the plugin version and the WordPress version. Returns an id for this connection, the service's limits and the check-in interval. A first sync follows.
* Check-in (`/checkin`): the connection id, the plugin version, the WordPress version and, if the last request failed, that error's code and message (up to 500 characters). When: about once a day from WP-Cron, when an administrator clicks "Check in now" or opens Samomind > Status (at most once a minute), or runs `wp samomind checkin`. Returns the connection status and how often to check in.
* Sync (`/sync` and `/documents`): when a sync starts and finishes, and in batches in between, for each published, public, not password-protected item of the post types ticked in Settings: its post id, address, title, text (the content as plain text, the excerpt and, for products, the short description), type (post, page, product or custom), last modified time and the names of its public categories and tags. Products add price and stock status, and for the product cards in the chat the address of the main product image (thumbnail size) and the price as the shop shows it, plus the regular price of a simple or external product on sale. When: on Connect, on "Sync now" and `wp samomind sync`. Returns, per item, whether it was accepted, unchanged or refused.
* Changes (`/documents` and `/documents/delete`): when an item is published, edited, unpublished, trashed or deleted, or when a post type is ticked or unticked. The first sends that item as above; the second sends only post ids. Sent about 30 seconds after the last save.
* Reconcile (`/reconcile`): the post id and last modified time of every included item, in pages, with the id range. When: daily after the check-in, "Reconcile now", `wp samomind reconcile`. Returns the ids Samomind lacks, holds out of date or has removed, which the plugin then sends or ignores.
* Disconnect (`/disconnect`): the connection id and the reason, "admin" or "uninstall". When: an administrator clicks Disconnect, or the plugin is deleted while connected. Deactivating sends nothing.

Never sent: drafts, scheduled, private or password-protected content, users, comments, form entries, orders, carts, customers, media files, the WordPress admin's data, or the list of other plugins.

**2. The Samomind chat widget, from your visitors' browsers**

Only if an administrator ticks "Show the Samomind chat widget on this site" and saves a site key. The plugin then adds one script tag to the pages visitors see (never the admin, login page, feeds or REST responses). The widget is off by default. No widget request of any kind happens unless an administrator switches the widget on; with it off, no page loads anything from Samomind.

* On every page view: the visitor's browser requests `widget.js` and `widget.css` from `https://widget.samomind.com`, and asks `https://api.samomind.com/api/v1/widget-appearance` for this site's colours and label, passing the site key. Samomind therefore sees the visitor's IP address, browser details and the address of the page.
* When a visitor opens the chat: the browser loads the chat script `chat.js` from `https://widget.samomind.com`. This is the service's own code, not a copy of a third-party library.
* When a visitor chats: the browser sends the site key and a random visitor id to `/api/v1/session`, then each message the visitor types, with the conversation id, to `/api/v1/chat`, and reads the answer with its sources. If the visitor opens their history or asks for a human, the browser also requests their earlier conversations, or sends the name, email address or phone number they type into that form. The random visitor id and the conversation id are kept in the visitor's browser (local storage) so the conversation continues; no cookie is set.

Like any web request, the visitor's browser requests to `widget.samomind.com` and `api.samomind.com` reach Samomind's servers with the visitor's IP address and browser user agent. See the privacy policy: https://samomind.com/privacy-policy/

**3. Order status in the chat (WooCommerce 8.2 or newer)**

Part of the Samomind service. Off by default. Only if an administrator ticks "Let customers check order status in the chat" under Samomind > Settings, accepts the order-data terms (the version, the administrator's user id and the time are recorded) and pastes an orders key from the Samomind dashboard. WooCommerce must be active; multisite networks are not supported. With this off, the plugin does nothing described in this section. The one exception is the short report below, which goes with the connect and check-in requests of section 1.

This is the one case where the plugin also **receives** requests from Samomind's servers: HTTPS POSTs to `/wp-json/samomind/v1/orders/` on this site. Each is signed with the orders key (a key separate from the ingest secret, never sent) and carries a timestamp and a one-time value; a request that is not validly signed is refused. The same WordPress site also answers its own visitors' browsers at `/wp-json/samomind/v1/orders/account-proof` (same site only, for a logged-in customer) and `admin-ajax.php?action=samomind_nonce`.

* When a customer asks the chat about an order and types an order number and the billing email, or only an email address to see all their orders, Samomind sends this site what was typed, to be checked. If it matches, **this site's own WordPress mail** sends a 6-digit code to that address, from the sender WooCommerce uses for its emails. The answer is the same whether or not the address has orders. The code is stored on this site only as a hash, for 10 minutes by default.
* When the customer enters the code, Samomind sends the code to be checked. If it is right, this site lets that one chat read that one order, or, after an email code, the orders of that email address, for 30 minutes by default. For the email address this site keeps it with the code and the permission for that time, then deletes it.
* A logged-in customer can skip the email: the browser asks this site for a signed proof that says the visitor is logged in (it names nobody), and this site records the login session it came from. The customer can then see the orders of their account. Logging out of the shop ends access at once.
* While access lasts, Samomind asks for a list of the customer's orders and for single orders. This site answers with the order number, status, dates, order total and number of items and, only if you share them, the item names with the address of each product photo, and the tracking carrier, number and link (read from WooCommerce Shipment Tracking or Advanced Shipment Tracking, if installed). Order lists and totals are sent only after a shopper proves their email address or is signed in. Never sent: addresses, phone numbers, email addresses, payment details, discounts, refunds, customer or order notes, the customer's name, or the orders of anyone else.
* Once order status is on, the connect and check-in requests of section 1 also carry a short report: whether order status is on, the version of the order-data terms accepted, whether order history is on, whether item names and tracking are shared, which tracking plugin was found (if any), whether the shop uses WooCommerce's order tables, the WooCommerce version, how code emails are sent, and the reason if it is off. After you switch it off, one more report says so, and then the report stops.
* "Share tracking" is on and "Share item names" is off by default; both are shown next to the opt-in and are enforced on this site.
* Samomind does not store the email address, the code or orders. See the privacy policy for what it keeps. The plugin's own request log never records an email, a code or order data.
* No order data is sent unless a customer proves who they are. With the setting off, nothing new leaves or enters this site, and no cookie is added.

No other outbound requests are made. There is no tracking, usage analytics or advertising. The plugin adds no links to your site's pages. When you switch the chat widget on, the chat window's footer shows "Powered by Samomind" with a link to https://samomind.com on plans that include it; it is left out on higher plans.

== Installation ==

1. In WordPress, go to Plugins > Add New Plugin, search for "Samomind" and click Install Now. (Or upload the zip under Plugins > Add New Plugin > Upload Plugin.)
2. Activate the plugin.
3. In the Samomind dashboard, open Channels, then Website: WordPress, and create a connection code. It works once and expires.
4. In WordPress, open Samomind > Settings, paste the code under "Connect to Samomind" and click Connect. The plugin sets itself up and the first sync starts in the background. If it asks you to finish in your dashboard, click Allow next to your website address under Channels > Website: WordPress.
5. Prefer to enter keys by hand? In the Samomind dashboard, open Channels > Website: WordPress > Advanced: show keys for manual setup. In WordPress, under "Advanced: enter keys manually" on Samomind > Settings, save the ingest key id and secret, then open Samomind > Status and click Connect. You can define `SAMOMIND_API_SECRET` in `wp-config.php` instead of saving the secret.
6. Optional, for WooCommerce 8.2 or newer: in the Samomind dashboard, open Orders > Setup and create an orders key. Under Samomind > Settings > Order status in the chat, paste it, accept the order-data terms, tick the box and save. Customers see all their orders after an email code once you have accepted the order-data terms version 2026-11-26. Then click Test connection in Orders > Setup.
7. Optional: to show the chat, tick "Show the Samomind chat widget on this site" under Samomind > Settings > Chat widget. Connecting with a code fills in the site key, and your site address must be allowed in the Samomind dashboard (Channels > Website: WordPress). There is nothing to paste into your theme or pages.

The full guide is at https://samomind.com/help/wordpress-plugin/.

== Frequently Asked Questions ==

= Do I need a Samomind account? =

Yes. The plugin is free, but it sends your content to your Samomind knowledge base, so you need a Samomind account and a connection code from the dashboard (Channels > Website: WordPress). Create an account at https://app.samomind.com. It starts with a 7-day free trial, and plans are on https://samomind.com/pricing/. Your Samomind plan sets how many pages the knowledge base holds; when the limit is reached the sync stops with `plan_page_cap` on Samomind > Status. Upgrade the plan or remove pages in the dashboard, then click "Sync now".

= What exactly is sent to Samomind? =

Only published, public content that is not password-protected, and only the post types ticked under Samomind > Settings > What is synced. Drafts, private content, users, comments, orders and customers are never part of the content sync. See "What is sent" above for the full list; order status is described under External services. Samomind > Status shows the last 200 requests (time, endpoint, HTTP status and error code); request bodies and credentials are never logged.

= Does it work with Elementor, Divi and other page builders? =

Yes. For each page the plugin finds the builder it was made with and asks that builder to render it, so the assistant gets the text visitors see. Elementor, Beaver Builder, Divi, WPBakery and Avada are supported, as are the block and Classic editors. Bricks and Oxygen keep their text in a form the plugin cannot render, so those pages may be sent with less text. If a builder fails or is switched off, the plugin sends the page's stored text instead and never sends raw shortcodes. Samomind > Status lists any pages that may be incomplete and why; a developer can supply the text of a page with the `SAMOMIND/content/text` filter.

= How quickly do changes show up in the assistant? =

A change is sent about 30 seconds after the last save (so several quick edits become one update), then Samomind indexes it. Stock and price changes made by orders, imports, the REST API, ERP or POS plugins and scheduled sales are sent the same way. A daily check-in and reconcile catches anything missed. WP-Cron only runs when the site gets visits, so on a quiet site set up a real cron job that runs `wp cron event run --due-now`, or install WooCommerce, whose Action Scheduler runs the work.

= Will it slow down my site? =

No. All syncing runs in the background in small batches, never while a visitor loads a page. The only thing added to the front end is the chat widget script, and only if you turn it on under Samomind > Settings > Chat widget. It loads deferred in the footer.

= The chat widget does not show. What do I check? =

The setting is on and a site key is saved; your site address (shown under the setting) is allowed in the Samomind dashboard (Channels > Website: WordPress); and you are looking at a page on the front end, not the admin or a preview. The plugin keeps WP Rocket, LiteSpeed Cache, Autoptimize, SiteGround Speed Optimizer, Jetpack Boost, Hummingbird, Perfmatters and NitroPack from combining, minifying, deferring or delaying the widget script, and FlyingPress from minifying it. If you use FlyingPress, also add `widget.samomind.com` to its Delay JavaScript and Defer JavaScript exclusions. W3 Total Cache leaves the script alone unless you list it under its minify external files. With any other optimisation plugin, exclude `widget.samomind.com` from its JavaScript combine, minify, defer and delay options.

= Can customers see all their orders in the chat? =

Yes, once you have accepted the updated order terms (version 2026-11-26) on Samomind > Settings. A customer types their email address, enters the 6-digit code your shop emails to them, and can then see the orders placed with that email address; a signed-in customer sees the orders of their account. A signed-in customer sees the orders of their account right away, without typing an order number. Nobody can see another email address's orders, and shops that have not accepted the updated terms keep the earlier one-order check.

= What happens when I deactivate, delete or disconnect? =

Deactivating only stops the plugin's scheduled work. Nothing is sent, your documents stay in Samomind and the connection is kept, so reactivating carries on without a full sync; changes made while it was inactive are picked up by the next daily reconcile. Deleting the plugin disconnects the site if it is still connected (Samomind then removes the documents it received from this site) and removes all of the plugin's settings, logs and scheduled events. Disconnect on the Status page removes your content from Samomind but keeps the plugin and its settings. Disconnect in the "Connect to Samomind" box on Samomind > Settings clears the keys on this site only; to stop the old keys working everywhere, create a new code and connect again. If someone disconnects the site in the Samomind dashboard, the dashboard wins: the plugin stops sending, shows "Disconnected from the Samomind dashboard." on Samomind > Status and does not reconnect until you click Connect.

== Screenshots ==

1. Samomind > Settings: connect with a code, the Advanced fold for entering keys by hand, the content types that are synced and the chat widget switch.
2. The content types list and the sync status, with the number of items kept in step with Samomind.
3. The chat widget's welcome screen on a live site, with suggested questions.
4. An answer from the chat widget, with a link to the source page.
5. The chat widget on a phone-sized screen.
6. Order status in the chat: the opt-in, the order-data terms, the orders key and what the assistant may say.

== Changelog ==

= 0.4.1 =
* New: product cards. Chat answers about your products can show a card with the photo and the price. After the update the plugin sends every product once more so the cards have their image and price.
* New: order history. After a 6-digit code emailed from your shop, or when signed in, a customer can see all their orders in the chat. It needs the updated order terms (version 2026-11-26): accept them on Samomind > Settings. Until you do, order status keeps working as before, one order at a time.
* Changed: the order total is now shown to a verified customer, and item names can come with their product photos.
* Changed: the Samomind dashboard's Install menu is now called Channels, with Website: WordPress. The plugin's texts follow.
* Changed: new short description and plugin description.

= 0.4.0 =
* New: order status in the chat, part of the Samomind service for WooCommerce 8.2 or newer. Customers check only their own orders, with a 6-digit code emailed from your shop or by being signed in. It is off until you switch it on and accept the order-data terms. You choose whether item names and tracking are shown. Works with the order tables (HPOS) on or off.
* New: connect with a code. Paste a one-time code from the Samomind dashboard under Samomind > Settings and the plugin sets itself up and starts the first sync. Entering keys by hand still works, under "Advanced".
* Changed: the API address must be https (plain http only for localhost).
* Fixed: the "WooCommerce is active, but products are not synced" notice now stays dismissed once you dismiss it.
* Changed: the notice about a paused or failing sync shows only on Samomind's own pages, the Plugins screen and the Dashboard, and goes away by itself once the problem is solved.
* Fixed: saving the key id or the API address when its setting was missing from the database could use up all memory. It now saves normally.

= 0.3.6 =
* Stock and price changes from orders, imports, the REST API and scheduled sales now reach the assistant within a minute. Clearer prices for sales and variable products. Hidden products are never sent.

= 0.3.5 =
* New name: Samomind. Signup is open to everyone. No change in behaviour.

= 0.3.4 =
* Escaping and autoload hardening for the WordPress.org review. Clearer External services section.

= 0.3.3 =
* New installs now sync only posts, pages and WooCommerce products by default; other content types can be ticked in Settings. Existing sites keep their current selection.

= 0.3.2 =
* Keeps the chat widget working with Jetpack Boost, Hummingbird, Perfmatters, FlyingPress and NitroPack optimisation.

= 0.3.1 =
* Keeps the chat widget working when SiteGround Speed Optimizer combines JavaScript files.

= 0.3.0 =
* The chat widget is now a setting: tick "Show the Samomind chat widget on this site" and enter the site key. The plugin loads the widget script itself, so nothing is pasted into the site and security scanners no longer flag an inline script.
* Sites that had pasted the old embed snippet keep their widget: the key is moved to the new setting on the first page load after the update.
* The widget script is kept out of WP Rocket, LiteSpeed Cache and Autoptimize combine, minify and delay options.
* Requires WordPress 6.4.

= 0.2.0 =
* Edits now reach the assistant: saves from any editor or page builder are queued, sent 30 seconds after the last save, and read fresh when sent.
* Page builder support: Elementor, Beaver Builder, Divi, WPBakery, Avada; Bricks and Oxygen read from stored content. Pages that may be incomplete are listed on the Status page.
* Ticking a post type again sends its items at once.
* The Status page checks in when opened, so a disconnect made in the Samomind dashboard shows immediately, and shows how many items are included on this site.

= 0.1.0 =
* First release: connect, full sync, event-driven updates and deletes, daily check-in and reconcile, request log, widget embed, WP-CLI commands.

== Upgrade Notice ==

= 0.4.1 =
Adds product cards and order history in the chat. To let customers see all their orders, accept the updated order terms on the settings page. The dashboard's Install menu is now called Channels.

= 0.4.0 =
Adds order status in the chat for WooCommerce 8.2 or newer (off until you switch it on) and Connect with a code on the Settings page. Entering keys by hand still works.

= 0.3.6 =
Stock and price changes from orders, imports, the REST API and scheduled sales now reach the assistant within a minute. Clearer prices for sales and variable products. Hidden products are never sent.

= 0.3.5 =
New name: Samomind. Signup is open to everyone. No change in behaviour.

= 0.3.4 =
Hardening for the WordPress.org review and a clearer External services section. No change in behaviour.

= 0.3.3 =
New installs start with posts, pages and products only. Your current selection is kept and nothing already sent is removed.

= 0.3.2 =
Keeps the chat widget working with more optimisation plugins. If you use FlyingPress, also add widget.samomind.com to its Delay JavaScript and Defer JavaScript exclusions.

= 0.3.1 =
Fixes the chat widget disappearing on sites where SiteGround Speed Optimizer combines JavaScript files.

= 0.3.0 =
The chat widget is now a setting under Samomind > Settings > Chat widget. If you pasted the old snippet, it is converted for you. Requires WordPress 6.4.

= 0.2.0 =
Fixes edits that did not reach the assistant, adds page builder support and re-sends a post type when you tick it again.

= 0.1.0 =
First release.
