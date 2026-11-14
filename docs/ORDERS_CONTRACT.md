# Orders contract (shop side)

> Copy of SamoMind docs/design/F-3-order-status.md, section 13 (SamoMind origin/main at 3f0f137). Do not edit here: change it in SamoMind and copy it again. Sections 2, 4, 5 and 11 of that file explain the rules this contract serves.

> **Plugin answers of 14 November 2026, pending the same change in SamoMind's design doc.** The text below is the copy of section 13 and has not been edited. These points differ from it or settle what it left open, and the plugin follows them:
>
> 1. **Proof signature.** `sig` is bare hex `HMAC-SHA256(orders key, "samomind-orders-v1-proof\n1\n<challenge>\n<exp>")`.
> 3. **Resend rules.** `limits` also carries `resend_gap_seconds` (default 60) and `sends_per_session_hour` (default 3). The shop holds them to a gap of at least 30 seconds and at most 10 sends an hour; when absent, the defaults apply.
> 4. **Mail cap.** `POST /orders/start` is always `202`, never `429`. When the shop's own hourly email cap (`shop_emails_per_hour`) blocked the mail, the signed body is `{"accepted": true, "mail_capped": true}`, for every start alike, whether or not the order exists. Otherwise it is `{"accepted": true}`.
> 6. **Tracking link.** `order.tracking.url` may be `null` (the carrier and number are kept) when the add-on's link is not https, has credentials, is over 2048 characters or has an IP address as its host.
> 8. **Old WooCommerce.** `capabilities.orders.reason` gains `woocommerce_too_old` (WooCommerce older than 8.2). Additive.
> 9. **Proof record lifetime.** The one-time record written at `account-proof` lives for the `account_proof_seconds` of the most recent signed request that carried it (any endpoint), default 60, at most 120.
>
> Also settled, no change to the text: `shipped` is `null` when tracking is not shared; the key id header is not pinned; an unredeemed account record cannot be deleted by `end` and expires in 60 seconds; the plugin checks the login session by the sha256 of its token against the user's session list, and a site with a custom session store gets `{"loggedIn": false}`.

> **Signed path (confirmed by the backend, T1a).** The path in the signed message is the shop's full request path as WordPress received it, including any WordPress sub-directory (a site at `https://example.com/blog` signs `/blog/wp-json/samomind/v1/orders/start`). The plugin checks the signature over the raw path of the request (`REQUEST_URI`, without the query string, not decoded). Only where the server gives no request URI does it rebuild the path from the home URL.

## 13. Implementation split and the contract

### Tasks and order

1. **Contract freeze** (this document approved; `docs/openapi.json` additive for the Samomind side; the plugin repository gets `docs/ORDERS_CONTRACT.md`).
2. **T1 Backend (golive)**: registry entry `woo_order_status`; migration (`order_keys`, `order_verifications`, `order_lookup_events`, all additive); orders key issue/revoke (dashboard routes, audited); the orders client (signing, SSRF guard, response verification, circuit breaker, `ping` and the connection-test route); the fifteen F-0 limits; the widget-facing endpoints; the account redeem flow; limits; the state machine; check-in capability storage (additive field on connect/check-in); `verified_order` plumbing into `chat/routes.py` and the graph; history masking; failure log; tests for every threat-table row marked backend. Can start as soon as the contract is frozen, tested against a fake shop (an in-process FastAPI app implementing the shop contract).
3. **T3 Plugin**: REST endpoints; signature and replay checks; code generation, hashing, transients; `wp_mail` sending; account proof with the shop-held one-time record, `account/redeem`, grants with session-token re-checks, `ping`, nonce endpoint and no-store headers; mail on `shutdown` after the finish function; settings (opt-in, terms acceptance, orders key, sharing choices); capability reporting; order lookup with HPOS and sequential numbers; tracking adapters; tests with WooCommerce on (HPOS on/off); readme and version bump. Runs in parallel with T1, against the same contract and the same fixtures.
4. **T2 Widget (golive)**: the order form and code panel, account challenge/proof flow, the "Check my order" offer event, "Forget this order", hidden-history placeholder, tracking chip, accessibility and 360 px layout (phone-layout tests stay off as decided; layout is checked by hand). After T1's endpoints exist (can use the fake shop).
5. **T4 Dashboard**: the orders key panel next to the ingest key, the "Set up in the plugin" checklist, the "Order lookups" failure log, locked card via F-0, admin Features screen shows the feature. After T1.
6. **T5 Security pass and end-to-end**: run every threat-table test against the real stack with a staging WooCommerce site; the F-3 adversarial pass (enumeration timing, injection corpus, replay, cached-page test with a real page cache plugin, shared-computer flow); fix findings with committed regression tests.
7. **T6 Legal and docs**: Terms, Privacy, DPA, plugin readme, RUNBOOK section, `docs/FEATURES.md` row to Built.
8. **Rollout**: Testing state with an On override for Samomind's own shop only; then one friendly tenant; then Live per plan. Switching the feature Off is the emergency control and needs no deploy.

Order: 1, then 2 and 3 in parallel, then 4, then 5, then 6 in parallel with 7; 8 last.

### API contract

All JSON, UTF-8. Errors use `{"detail":{"code","message"}}` (`D-7.6-ingest-error-shape`) on Samomind; the shop's errors are always the generic bodies shown below.

### Check-in addition (plugin to Samomind, existing `connect` and `checkin`, additive optional field)

```json
{ "capabilities": { "orders": { "api": 1, "enabled": true, "terms_accepted_version": "2026-11",
  "share_items": true, "share_tracking": true, "tracking": "wc_shipment_tracking",
  "hpos": true, "woocommerce": "9.4.1", "mail_dispatch": "shutdown", "reason": null } } }
```

### Widget to Samomind (visitor JWT; tenant and visitor from the token only; request models forbid extra fields)

```
GET  /api/v1/orders/availability
 -> 200 {"available": true, "methods": ["guest","account"]}   (404 when the feature is off or not opted in)

POST /api/v1/orders/verify/start
 {"conversation_id": uuid, "order_number": "1234", "email": "a@b.co"}
 -> 202 {"state": "code_sent_if_details_match", "verification_id": uuid, "expires_in": 600}
 -> 429 {"detail": {"code": "locked", "message": "Too many attempts. Please contact the shop."}}
 -> 503 {"detail": {"code": "unavailable", "message": "..."}}

POST /api/v1/orders/verify/confirm
 {"verification_id": uuid, "code": "123456"}
 -> 200 {"state": "verified", "order_number": "1234", "expires_in": 1800}   (expires_in = grant_minutes)
 -> 200 {"state": "failed"}
 -> 429 locked  |  503 unavailable

POST /api/v1/orders/account/challenge
 {"conversation_id": uuid}
 -> 200 {"challenge": "<22 chars>", "expires_in": 60}

POST /api/v1/orders/account/verify
 {"conversation_id": uuid, "order_number": "1234",
  "proof": {"v": 1, "challenge": "<22 chars>", "exp": 1731412345, "sig": "<hex 64>"}}
 -> 200 {"state": "verified", "verification_id": uuid, "order_number": "1234", "expires_in": 1800}
 -> 200 {"state": "failed"}     (every failure alike, including the shop refusing to redeem)

DELETE /api/v1/orders/verifications/{verification_id}
 -> 204   (ends it and asks the shop to delete its grant)
```

Chat addition (`POST /chat` is unchanged on the wire): the SSE stream gains an event `order_offer` `{ "reason": "order_intent" }` when code detects the intent, and `order_tracking` `{ "carrier": "...", "number": "...", "url": "https://..." }` after a verified reply that has tracking. Conversation messages carry `order_details: true` internally; history endpoints return the placeholder text.

### Samomind to shop (signed as in section 2; base `{site_url}/wp-json/samomind/v1`)

`limits` is the object of values the shop must enforce, from the F-0 limits in section 4: `{"code_ttl_minutes":10,"code_tries":3,"shop_emails_per_hour":20,"grant_minutes":30,"account_proof_seconds":60}`. The shop clamps each to its hard ceiling.

```
POST /orders/ping
 {"request_id": uuid}
 -> 200 {"plugin_version": "0.9.0", "api": 1, "capabilities": {...as at check-in...}, "server_time": 1731412345}
 -> 401 {"code": "invalid_signature", "server_time": 1731412345}    (the only failure body with data)

POST /orders/start
 {"request_id": uuid, "session_ref": "<hex 32>", "order_number": "1234",
  "email": "a@b.co", "locale": "en", "limits": {...}}
 -> 202 {"accepted": true}                   (always, after the floor delay; the mail goes out on shutdown)

POST /orders/verify
 {"request_id": uuid, "session_ref": "<hex 32>", "order_number": "1234", "code": "123456", "limits": {...}}
 -> 200 {"verified": true,  "grant_expires_in": 1800}
 -> 200 {"verified": false}                  (every failure alike)

POST /orders/account/redeem
 {"request_id": uuid, "session_ref": "<hex 32>", "challenge": "<22 chars>",
  "order_number": "1234", "limits": {...}}
 -> 200 {"granted": true,  "grant_expires_in": 1800}
 -> 200 {"granted": false}                   (no record, used, expired, wrong customer, guest order, unknown order: alike)

POST /orders/lookup
 {"request_id": uuid, "session_ref": "<hex 32>", "order_number": "1234",
  "share": {"items": true, "tracking": true}}
 -> 200 {"found": true, "order": {
      "order_number": "1234", "status": "processing", "status_label": "Processing",
      "created": "2026-11-08", "paid": "2026-11-08", "shipped": null, "completed": null,
      "items": [{"name": "Blue mug", "qty": 2}],
      "tracking": {"carrier": "DHL", "number": "JD0146", "url": "https://..."} }}
 -> 200 {"found": false}                     (no grant, expired grant, logged-out account session, unknown order)

POST /orders/end
 {"request_id": uuid, "session_ref": "<hex 32>"}
 -> 204   (deletes any grant, pending code and unredeemed record for that session; unknown refs are fine)
```

`lookup` takes no identity of any kind and no method: it is authorised only by a grant the shop created itself, by `verify` (an emailed code) or by `account/redeem` (a shop-written one-time record for a live login session). `account_tag` no longer exists anywhere in the contract.

Common failure bodies from the shop: `401 {"code":"invalid_signature"}` (any signature, key, nonce or timestamp problem, one body; with `server_time` as above), `403 {"code":"not_enabled"}` (not opted in), `429 {"code":"rate_limited"}`. Response header `X-SamoMind-Orders-Signature` as in section 2.

### Dashboard (owner session, CSRF; recent sign-in to issue or revoke a key)

```
POST /api/v1/dashboard/orders/test-connection
 -> 200 {"ok": true, "plugin_version": "0.9.0", "mail_dispatch": "shutdown"}
 -> 200 {"ok": false, "cause": "redirect | blocked | rest_disabled | bad_key | clock_skew | unreachable | not_enabled",
         "detail": "<the plain sentence from section 2>"}

POST /api/v1/dashboard/orders/key      (issue or rotate; the secret is shown once; runs a ping)
DELETE /api/v1/dashboard/orders/key    (revoke)
GET  /api/v1/dashboard/orders/lookups  (the failure and alert log, 30 days)
```

### Widget to shop (same origin, cookie + `X-WP-Nonce`; no CORS)

```
GET  /wp-admin/admin-ajax.php?action=samomind_nonce     -> 200 {"nonce": "..."}   (no-store, private)
POST /wp-json/samomind/v1/orders/account-proof
 {"challenge": "<22 chars>"}
 -> 200 {"loggedIn": true, "proof": {"v":1,"challenge":"...","exp":1731412345,"sig":"<hex 64>"}}
 -> 200 {"loggedIn": false}
```

The shop writes its own one-time record when it returns `loggedIn:true` (section 5). The proof names no user.

