# Reviewer note for the WordPress.org submission form

Paste everything under the line into the note field. Fill the three
bracketed lines yourself before you submit. Never commit real credentials
to this repository.

---

Samomind AI Assistant is the WordPress connector for Samomind, our own
hosted AI assistant service. The service is run by SoftwaresKe Ltd, trading
as Samomind, which owns the Samomind name and the samomind.com domain.
The submitting account uses a samomind.com address.

The plugin keeps a site's public pages, posts and products in the visitor's
chat assistant. Nothing is sent until an administrator enters an ingest key
and clicks Connect, and activation sends nothing. The chat widget is off by
default; an administrator switches it on and saves a site key, and the
plugin then loads the one declared script with wp_enqueue_script. Every
request, with its fields, is listed under "External services" in readme.txt
with links to our terms and privacy policy. There is no telemetry and no
"powered by" link added to the site.

Plans, trials and message limits belong to the hosted service, as allowed
by guideline 6. The plugin itself has no locked, time-limited or paid
features, and all of its code is in the zip, readable, with no build step
and no bundled libraries (guidelines 4, 5 and 13).

A test account is provided so you can try the connection and the widget:

Test dashboard: [Felix fills in]
Site key: [Felix fills in]
Ingest key: [Felix fills in]

To see the widget on your test site, add that site's address to Allowed origins in the test dashboard.

Thank you for the review.
