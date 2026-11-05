# 7.6.c acceptance: pilot site checklist

Manual run on the pilot site (www.softwareske.com) against production
(`https://api.samomind.com`), with a production ingest key. Contract
sections refer to `docs/API_CONTRACT.md`. The plugin's request log
(SamoMind > Status > Request log) shows one row per request: endpoint, HTTP
status, code. Bodies are never logged, so the "expected response" column
is what the contract says the API returns; the plugin's status page shows
the parts it stores.

Tip: the work runs on WP-Cron, which needs visits. To run it at once from
a shell, use `wp cron event run --due-now`, or the matching `wp samomind`
command.

## 0. Before you start

- [ ] Plugin zip built without development files (`.distignore`), version
      0.1.0, installed and activated. No PHP notices in `debug.log`.
- [ ] SamoMind dashboard: Sources > WordPress > **Create an ingest key**.
      Copy the key id and secret (shown once).
- [ ] SamoMind > Settings: save the secret (field shows "A secret is
      saved", the value is never shown), then the key id. API base URL is
      `https://api.samomind.com`.
- [ ] View the page source of SamoMind > Settings: the secret does not
      appear anywhere.

## 1. Connect

| Step | Expected |
|---|---|
| SamoMind > Status > **Connect** | Notice "Connected. The first sync has started in the background." |
| Request log | `/connect` 200 |
| API response (5.5) | `source_id` (UUID), `checkin_interval_seconds` 86400, `limits` (`max_documents_per_push` 50, `max_body_bytes` 2097152, `max_text_chars` 200000, `max_delete_ids` 500, `max_reconcile_entries` 5000), `sync` (`state`, `cursor`) |
| Status page | Connected, the source id, "Connected since" now |
| Dashboard WordPress card | `https://www.softwareske.com`, connector `wordpress` 0.1.0, last check-in now |

- [ ] Done

## 2. Full sync

| Step | Expected |
|---|---|
| Wait, or `wp samomind sync` | Request log: `/sync` 200, then `/documents` 200 (one per batch of up to 50), then `/sync` 200, then `/reconcile` 200 |
| `/sync` begin response (5.5) | `state` `in_progress`, `cursor` null (or the last stored cursor after an interrupted sync) |
| `/documents` response | `results`: each item `queued` (new) or `unchanged`; any `refused` has a `code` (`off_site_url` or `plan_page_cap`) |
| `/sync` complete response | `state` `complete` |
| `/reconcile` response (5.6) | `missing` [], `stale` [], `deleted` [] right after a clean sync |
| Status page | Full sync "Complete", "N sent of about M published items", N accepted, 0 refused; Reconcile "Complete" |
| Dashboard | page count for the site equals the published pages, posts and products |
| Widget / chat | a question about a page's content is answered with a citation to that page's real permalink |

- [ ] Done
- [ ] If the plan's page cap is hit: status shows "Stopped" with the
      plan_page_cap message, and no `/sync` complete is sent.

## 3. Edit a page and see the update

Do this once for **every editor the site uses**: the block editor, the
Classic editor if used, and each page builder installed (Elementor,
Beaver Builder, Bricks, Oxygen, Divi, WPBakery, Avada). Use a published
page built with that editor.

| Step | Expected |
|---|---|
| Edit the page and add a sentence with a **unique word** that appears nowhere else (for example "zebrafruit"), click Update / Publish | nothing is sent for about 30 seconds (the save is queued, "Waiting to send" 1), then `/documents` 200 with the page's item `queued` |
| Edit the page twice more within 30 seconds (change the unique word each time) | still one `/documents` request, carrying the last word |
| Ask the widget a question that needs the unique word ("what is zebrafruit?") | within a few minutes the answer uses the new sentence, without clicking Sync now |
| Status page, "Pages that may be incomplete" | the page is not listed (a Bricks or Oxygen JSON layout, or a Divi/Avada page on a host where the builder is not loaded for background requests, can be listed: note the reason shown) |

Record the builder, its version and the result for each:

| Editor | Version | Unique word answered? | Listed as incomplete? |
|---|---|---|---|
| Block editor | | | |
| Elementor | | | |
| (other builders installed) | | | |

- [ ] Done

## 4. Trash a page and see the delete

| Step | Expected |
|---|---|
| Move a published page to the Trash | `/documents/delete` 200 |
| API response | `results`: the page's id `queued` |
| Chat / dashboard | the page is no longer cited; the site's page count drops by one |
| Restore and publish it again | `/documents` 200, item `queued`, cited again |

Also check (each gives `/documents/delete` 200, item `queued`):

- [ ] Switch a published page to Draft.
- [ ] Make a published page Private.
- [ ] Give a published page a password.

And nothing at all for:

- [ ] Saving a draft (no request in the log).

## 4b. Untick a post type

| Step | Expected |
|---|---|
| SamoMind > Settings > What is synced: all types ticked by default | checklist shows Pages, Posts, any public custom types, Products (with WooCommerce) |
| Untick Posts, Save Settings | `/documents/delete` 200 (one request per 500 ids) with every published post id; items `queued` (or `not_found` for any SamoMind never had) |
| Chat / dashboard | posts are no longer cited; the page count drops by the number of posts |
| Publish a new post | no request |
| Tick Posts again, Save (no Reconcile now) | `/documents` 200 at once, pushing every published post; Status shows "Included on this site" back to the earlier number; the dashboard count is restored and the posts are cited again |
| Status page "Included on this site" after the untick | the number of published items of the ticked types only (not the last full sync's figure) |
| Untick and tick Posts again before the first worker run | no `/documents/delete`, one `/documents` push |

- [ ] Done

## 5. Reconcile

| Step | Expected |
|---|---|
| SamoMind > Status > **Reconcile now** (or `wp samomind reconcile`) | `/reconcile` 200 (one per 5000 items) |
| API response (5.6) | `missing`, `stale`, `deleted`, all empty when in sync |
| Status page | Reconcile "Complete" with "0 missing, 0 stale, 0 removed by SamoMind" |

- [ ] Done

## 6. Check-in

| Step | Expected |
|---|---|
| SamoMind > Status > **Check in now** (or `wp samomind checkin`) | `/checkin` 200 |
| API response (5.5) | `status` `ok`, `checkin_interval_seconds` 86400, `supported_api_versions` contains `"1"` |
| Status page | "Last check-in" now, "(SamoMind reports: ok)" |
| Dashboard | last check-in updated |
| Next day | the hourly WP-Cron event checks in once the interval has passed, then reconciles |

- [ ] Done

## 7. Widget

| Step | Expected |
|---|---|
| Paste the dashboard's Widget snippet into SamoMind > Settings > Embed snippet, Save | saved without an error |
| View any front-end page source | one `<script src="https://widget.samomind.com/widget.js" data-site-key=... data-base-url=... async>` before `</body>` |
| Paste `<script>alert(1)</script>` instead | refused with a message; the previous snippet is kept |

- [ ] Done

## 8. Error handling

| Step | Expected |
|---|---|
| Save a wrong secret, click Reconnect | notice "Connect failed: ... (invalid_signature)" with the API's message verbatim; log shows `/connect` 401 `invalid_signature` twice (one retry with a fresh timestamp) |
| Save the right secret again, Reconnect | connected again (connect is idempotent per site, same source id) |
| Create a new key in the dashboard (revokes the old one), then edit a page | `/documents` 401 `key_revoked`; status "Connected, paused" and an admin notice; nothing else is sent |
| Paste the new key id and secret | the pause clears; the edit is sent on the next worker run |

- [ ] Done

## 9. Disconnect

| Step | Expected |
|---|---|
| SamoMind > Status > **Disconnect**, confirm | `/disconnect` 200 |
| API response (5.5) | `state` `disconnected`; every document of the source is removed |
| Status page | Not connected; settings (key id, secret, snippet) kept |
| Dashboard | the site shows "disconnected" |
| Edit a page | no request |
| Connect again | `/connect` 200, a new full sync |

- [ ] Done

### 9b. Disconnect from the dashboard

| Step | Expected |
|---|---|
| In the SamoMind dashboard, Disconnect the site | documents removed there |
| Edit a published page on the site (or **Check in now**) | one request, answered 409 `source_disconnected` |
| Status page | "Disconnected from the SamoMind dashboard." with a **Connect** button; source id gone; an admin notice says nothing is sent until Connect |
| Edit another page, wait past the next hourly cron | no request at all; never a `/connect` |
| Click **Connect** | `/connect` 200, a new full sync |
| After a dashboard disconnect, just open the Status page (no edit, no button) | within one page load the page shows "Disconnected from the SamoMind dashboard." (a single `/checkin` 409 in the log; at most one per minute); never a `/connect` |

- [ ] Done

## 10. Deactivate and reactivate

| Step | Expected |
|---|---|
| Deactivate the plugin while connected | no request in the log; `wp cron event list` has no `SAMOMIND/*` events |
| Dashboard | the site is still listed with its page count; documents still present (the chat still cites the site's pages) |
| Reactivate the plugin | no request; SamoMind > Status shows Connected with the same source id; Full sync still shows the earlier "Complete" (no new full sync starts) |
| Edit a published page | `/documents` 200, item `queued` (events work again) |

- [ ] Done

## 11. Uninstall

| Step | Expected |
|---|---|
| Deactivate, then Delete the plugin while connected | `/disconnect` 200 with reason `uninstall`; dashboard shows disconnected and the documents are removed |
| After deletion | every `samomind_*` option and the `SAMOMIND/*` cron events are gone (`wp option list --search='samomind_*'` is empty, `wp cron event list` has none) |

- [ ] Done
