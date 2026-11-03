<!-- Copied unchanged from SamoMind docs/ingest/PUSH_INGESTION_DESIGN.md at commit ba44704d3282672b5bfaf63b32f9134b1aea251e (origin/main). -->
# Push ingestion: design, as built (Step 7.6)

Kickoff 2026-11-02 (`docs/wordpress/`, track/golive); amended the same day
by Felix's decisions (`D-7.6-cms-neutral-push`, `D-7.6-ingest-token-design`
APPROVED as built, `D-7.6-key-rotation`, `D-7.6-no-domain-check`,
`D-7.6-ingest-error-shape`, `D-api-enum-additions-additive`, IC-9 built
here) and built at 7.6.a; amended 2026-11-03 at 7.6.a1 after the first
connector build (`D-7.6-deactivate-no-op`,
`D-7.6-dashboard-disconnect-wins`; docs only, no API change). This is the
contract a connector builds against;
`docs/openapi.json` carries the same paths and schemas. Code:
`backend/app/ingest/push.py` (request side), `push_routes.py` (endpoints),
`push_schemas.py` (bodies), `push_adapter.py` (worker side),
`app/dashboard/source_routes.py` (dashboard routes).

The API is CMS-neutral: WordPress is its first connector, not its shape
(section 9 holds the WordPress notes). The connector's own architecture is
not designed here (`D-7.6-division-of-work`).

## 1. Why

Production cannot fetch sites behind host bot protection (softwareske.com
answers the server's datacenter IP with a SiteGround challenge,
`D-7.5-bot-protection`, `D-7.5-wordpress-push`); Felix will not ask hosts
to allowlist, and data never moves from a development machine to
production (`D-7.5-no-dev-to-prod-data`). A connector inside the tenant's
site pushes its public content out to us, so bot protection never applies
and content stays fresh without crawling. Pushed content is production
data: it is evaluated only in production (`D-7.5-eval-split`, Step 7.7).

## 2. Source type `push`

- One push source per connected site: `sources.type = 'push'`, unique per
  (tenant, `config.site_url`). Created by the first `connect` from that
  site; never from the dashboard's "Add source" (the generic create route
  accepts only `urls` and `crawl`).
- `sources.config`: `site_url` (normalised: scheme, lowercase host, port
  only if not the default, path without a trailing slash), `connector`
  (`wordpress`, `shopify`, `custom`, ...: a lowercase label), the reported
  `connector_version` and `platform_version`, `connected_at`,
  `last_checkin_at`, `last_error`, `sync` (`state`, `cursor`) and
  `disconnected_at`. No secret.
- Documents: identity is (source, `external_id`), an opaque, stable string
  the connector supplies (1 to 200 visible ASCII characters; WordPress sends
  its post id). `url` is a separate, updatable column used for citations;
  `kind` is `page`, `post`, `product` or `custom`.
- Writes go through `push_items`, the latest state the connector asked for
  per (source, external_id). A push or delete updates that row (bumping its
  `version`) and queues one `ingest_push` job per source; the worker applies
  the newest version, so a retried or reordered job can never bring back a
  deleted document. Embedding happens only in the worker (SPEC "parsed in
  the worker").
- A changed URL with unchanged content repoints the existing Qdrant points'
  `source_url` and the document row; nothing is re-embedded.
- Status (dashboard): `disconnected` when the connector called disconnect
  (or a tenant did from the dashboard), or has not checked in for 72 hours
  (`PUSH_DISCONNECTED_AFTER_SECONDS`); otherwise the usual job-derived
  status. A push source is never scheduled or "refreshed now".
- The plan's page cap (`total_indexed_pages`, IC-9) counts push documents
  with URL-list and crawl pages; uploads and database rows do not count.

## 3. Credentials

- One active ingest key per tenant (`ingest_keys`), issued in the dashboard
  (recent sign-in and CSRF) or with the admin CLI. Issuing revokes the
  active key at once (`D-7.6-key-rotation`); the dashboard warns that
  connected sites stop until the new key is pasted in.
- A key is a key id (UUID) and a secret (`smik_` + 43 URL-safe characters).
  The secret is shown once and stored encrypted with
  DB_CONNECTION_ENCRYPTION_KEY (pgcrypto, the 2.8 mechanism for database
  credentials), because verifying an HMAC needs the secret itself
  (`D-7.6-ingest-token-design`, superseding "hashed at rest"). It never
  appears in logs, the audit log, `email_log` or any later response
  (tested).
- The connector never sends the secret: it sends the key id and a
  signature (section 5.2).
- No verified-domain requirement to connect: the key is the proof of
  control (`D-7.6-no-domain-check`). Every connected site is listed in the
  dashboard with its reported URL, connector, last check-in and page count,
  so an unexpected one is visible; issue, revoke, connect and disconnect
  are audited.

## 4. What a connector sends, and never sends

Per document (`PushDocument`):

| Field | Type | Notes |
|---|---|---|
| `external_id` | string, 1 to 200 visible ASCII | stable identity |
| `url` | `http(s)://` URL, at most 2048 | must be on the connected site's host, or the item is refused `off_site_url` |
| `kind` | `page`, `post`, `product`, `custom` | |
| `title` | string, at most 500 | plain text |
| `text` | string, at most `INGEST_MAX_TEXT_CHARS` (200,000) | rendered content reduced to clean text |
| `modified_at` | RFC 3339 with offset | the content's last modified time |
| `fields` | optional: `price` (64), `stock_status` (32), `categories` (up to 50, each 100) | embedded as a short "Details" block |

Never: drafts, scheduled, private or password-protected content, anything
not publicly visible, user data, comments, form entries, orders, media
files or PDFs (out of v1, `D-7.6-content-scope`). Every string is untrusted:
NUL characters are refused, and the text reaches the model only inside a
DATA block, exactly like a crawled page (tested with the golden set's
injection canary).

## 5. The protocol

All endpoints are `POST`, JSON, on the API host (`https://api.samomind.com`
in production), under `/api/v1/ingest`. Server to server: no cookies, no
CORS.

### 5.1 Headers on every request

| Header | Value |
|---|---|
| `X-SamoMind-API-Version` | `1` |
| `X-SamoMind-Key-Id` | the key id |
| `X-SamoMind-Timestamp` | Unix seconds, UTC |
| `Idempotency-Key` | 8 to 128 visible ASCII characters, new for every request; reuse it only to retry a request whose response never arrived |
| `X-SamoMind-Signature` | `v1=<hex HMAC-SHA256>` (5.2) |
| `Content-Type` | `application/json` |

### 5.2 Signing

```
message   = timestamp + "\n" + key_id + "\n" + idempotency_key + "\n"
          + METHOD + " " + path + "\n" + body
signature = "v1=" + hex(HMAC-SHA256(key = secret as UTF-8, message))
```

`METHOD` is `POST`. `path` is the request path only, exactly as sent, for
example `/api/v1/ingest/checkin`: no scheme, no host, no query string, no
trailing slash. `body` is the exact bytes sent. The server refuses a
timestamp more than 300 seconds from its clock
(`INGEST_SIGNATURE_WINDOW_SECONDS`) and compares in constant time. Worked
example (pinned by `backend/tests/test_push_signing.py`):

- secret `smik_EXAMPLE-ONLY-not-a-real-secret-0000000000`
- key id `3f2b6c1e-8d4a-4f0b-9a7e-2c5d1b6e9f10`
- timestamp `1793620800`, Idempotency-Key `checkin-2026-11-02-0001`
- method and path `POST /api/v1/ingest/checkin`
- body `{"source_id":"8c1d2e3f-4a5b-4c6d-8e7f-9a0b1c2d3e4f"}`
- signature `v1=664538142402a7c58c0b3dba80295e15ecf0b3b6c49727cf290d97044b6d0fe8`

The signature covers the method and path (amendment to
`D-7.6-ingest-token-design`, 2026-11-03), so a captured request sent to
another endpoint fails the signature (tested: a valid `/checkin` signature
is refused on `/disconnect` with the same body). Two further layers stay: a
signed body only parses on the endpoint it was meant for (every body is
strict and has a field no other endpoint accepts), and an Idempotency-Key is
spent once per key across all endpoints.

### 5.3 Order of checks

Nothing is written before the last of these passes: body size (413, while
reading, with or without Content-Length); per-IP rate; API version; the
signature headers; the timestamp window; the key (exists, not revoked); the
HMAC; per-key rate; the body's shape; the tenant is active; the
Idempotency-Key is unused (reserved in the request's own transaction, so a
request that fails later does not spend it).

### 5.4 Errors

Every refusal: `{"detail": {"code": "<code>", "message": "<text>"}}`
(`D-7.6-ingest-error-shape`; the rest of the API is unchanged). Responses
carry `X-SamoMind-API-Version`.

| Status | Code | Connector should |
|---|---|---|
| 400 | `unsupported_api_version` | stop; update the connector |
| 400 | `invalid_body`, `invalid_idempotency_key`, `invalid_site_url`, `too_many_documents`, `text_too_long`, `too_many_ids`, `too_many_entries`, `duplicate_external_id`, `invalid_range`, `entry_out_of_range` | not retry; fix the request |
| 401 | `invalid_signature`, `stale_timestamp` | check the secret and the clock; retry once with a fresh timestamp |
| 401 | `invalid_key`, `key_revoked` | stop; ask the site admin to paste the current key |
| 403 | `tenant_suspended` | stop; check in daily |
| 404 | `unknown_source` | call `connect` again |
| 409 | `source_disconnected` | stop all background work (pushes, deletes, check-ins, reconcile, first sync); tell the site admin the site was disconnected from the SamoMind dashboard; do not call `connect` again until the admin explicitly reconnects in the connector |
| 409 | `source_disabled` | stop; the tenant disabled the source in the dashboard |
| 409 | `duplicate_request` | the request with this Idempotency-Key was already applied; do not resend it |
| 409 | `connect_conflict` | retry `connect` |
| 413 | `body_too_large` | split the batch |
| 429 | `rate_limited` (with `Retry-After`) | wait, then retry |
| 5xx, network | none | retry with backoff (5.7) |

Per-item refusals inside a 200 from `/documents`: `off_site_url`,
`plan_page_cap` (do not resend that item until something changes).

A disconnect made in the SamoMind dashboard wins
(`D-7.6-dashboard-disconnect-wins`): the tenant removed the site, so a
connector that reconnected on its own would undo that decision and push
everything back. Only an explicit admin action in the connector (for
example a "Reconnect" button) calls `connect` after `source_disconnected`.
The server's message for this code says the same: "This site was
disconnected from the SamoMind dashboard; reconnect from the connector only
when the site admin intends it."

### 5.5 Endpoints

| Path | Body | Response |
|---|---|---|
| `/connect` | `site_url`, `connector`, `connector_version?`, `platform_version?` | `source_id`, `checkin_interval_seconds` (86400), `limits`, `sync` |
| `/documents` | `source_id`, `documents` (1 to 50), `sync_cursor?` | `results`: per item `queued`, `unchanged` or `refused` with `code` |
| `/documents/delete` | `source_id`, `external_ids` (1 to 500) | `results`: `queued` or `not_found` |
| `/sync` | `source_id`, `action` (`begin`, `complete`) | `state` (`none`, `in_progress`, `complete`), `cursor` |
| `/reconcile` | `source_id`, `range_start?`, `range_end?`, `entries` (up to 5000 `{external_id, modified_at}`) | `missing`, `stale`, `deleted` |
| `/checkin` | `source_id`, `connector_version?`, `platform_version?`, `last_error?` | `status`, `checkin_interval_seconds`, `supported_api_versions` |
| `/disconnect` | `source_id`, `reason` (`deactivate`, `uninstall`, `admin`) | `state: "disconnected"`; every document of the source is removed |

`connect` is idempotent per site and reconnects a disconnected one.

### 5.6 First sync and reconcile

- First sync: `connect`, then `/sync begin`; push every eligible document
  in a stable order, each batch carrying `sync_cursor` (opaque to us, at
  most 200 characters: the connector's own resume point); `/sync complete`
  at the end. After a failure, `/sync begin` (or `connect`) returns the
  last cursor stored, and the connector resumes after it.
- Reconcile (daily, after the check-in, and after a first sync): the
  connector pages through its inventory sorted by `external_id` in byte
  order. Each page names its range: every live document whose id sorts
  after `range_start` (exclusive) and up to `range_end` (inclusive); null
  means open at that end, so a single page with both null covers
  everything. The server answers `missing` (push these), `stale` (its
  `modified_at` is newer than ours: push again) and `deleted` (ours, in the
  range, not listed: removed by the server). An entry outside its page's
  range is refused. Note: WordPress post ids sort as strings ("10" before
  "9").

### 5.7 Retries

Retry only 429, 5xx and network failures, with the same Idempotency-Key
and a fresh timestamp: wait `Retry-After` on 429; otherwise 1 min, 5 min,
15 min, 1 h, 6 h, then every 24 h. One request in flight per site. After 24
hours of failures, tell the site admin. A `duplicate_request` on a retry
means the original was applied.

## 6. Limits (settings, tunable)

`INGEST_MAX_BODY_BYTES` 2 MiB; `INGEST_MAX_DOCUMENTS_PER_PUSH` 50;
`INGEST_MAX_TEXT_CHARS` 200,000; `INGEST_MAX_DELETE_IDS` 500;
`INGEST_MAX_RECONCILE_ENTRIES` 5000; `INGEST_SIGNATURE_WINDOW_SECONDS` 300;
`INGEST_IDEMPOTENCY_RETENTION_HOURS` 24; `INGEST_RATE_LIMIT_PER_KEY` 60 per
60 s; `INGEST_RATE_LIMIT_PER_IP` 120 per 60 s (checked before any key
lookup); `PUSH_CHECKIN_INTERVAL_SECONDS` 86400;
`PUSH_DISCONNECTED_AFTER_SECONDS` 259200.

## 7. Security boundaries

- The tenant comes only from the verified key, never from a body; every
  query filters on it; a key from tenant A reaches nothing of tenant B
  (tested with planted data).
- The ingest routes are API-host only (`require_api_host`, tested); the
  request side never touches Qdrant (`push_adapter.py` is the only push
  module allowed a Qdrant client).
- Nothing calls the tenant's site. The connector calls only its configured
  API URL.
- Suspended tenants are refused before any write; their jobs do not run.

## 8. Dashboard

Sources has a **WordPress** card: connect steps, the active key's id and
age, "Create an ingest key" / "Create a new key" (with the rotation
warning) and "Revoke key", the secret shown once after creating, and a
table of every connected site (URL, connector and version, last check-in,
pages, status, Disconnect with a confirmation naming what is removed). Push
sources also appear in the main sources table, without "Refresh now". An
unknown status shows "Unknown" (`D-api-enum-additions-additive`).

Dashboard routes (session, CSRF; recent sign-in on the three changes):
`GET /api/v1/dashboard/ingest/push`, `POST /api/v1/dashboard/ingest/keys`,
`POST /api/v1/dashboard/ingest/keys/revoke`,
`POST /api/v1/dashboard/ingest/push/{source_id}/disconnect`. CLI fallback:
`admin_cli issue-ingest-key` / `revoke-ingest-key` (RUNBOOK).

## 9. WordPress connector notes

- `connector: "wordpress"`; `external_id` is the post id as a string;
  `kind` is `page`, `post`, `product` (WooCommerce) or `custom` (a public
  custom post type the admin selected); `platform_version` is the
  WordPress version.
- Send on publish and on update of a published post (debounced); delete on
  trash, unpublish, a change to private or password-protected, and
  permanent deletion; first sync on connect; check-in then reconcile daily.
- Deactivating the plugin does nothing server-side
  (`D-7.6-deactivate-no-op`): the source and its documents stay, and the
  site shows "disconnected" in the dashboard after 72 hours without a
  check-in, until it is reactivated and checks in again. Only an explicit
  disconnect (the plugin's own Disconnect action or its CLI command, sent
  with `reason: "admin"`) or uninstall (`reason: "uninstall"`) calls
  `/disconnect`, and `/disconnect` removes every document of the source.
  On uninstall the plugin then removes every option and scheduled event it
  created. The API still accepts `reason: "deactivate"` (unchanged
  schema); this connector does not send it.
- `text` is the rendered content (`the_content` filters run, so shortcodes
  render) reduced to text; theme header, footer, menus and widgets are not
  part of a post and are never sent. Products add `fields`.
- Minimums: WordPress 6.0, PHP 7.4, no PHP 8-only syntax
  (`D-7.6-min-versions`). Pin `X-SamoMind-API-Version: 1`.
- The plugin: **SamoMind AI Assistant**, slug `samomind-ai-assistant`,
  repository `felixkipyego/samomind-ai-assistant`, built at 7.6.b.
  Distribution and repository: `D-7.6-distribution`, `D-7.6-separate-repo`,
  docs/wordpress/REPO_BOOTSTRAP.md.

## 10. Acceptance line for 7.6

1. to 11. (7.6.a, this repository): a key is shown once and stored
encrypted; every refusal in 5.3 happens before any write (signature: valid,
wrong secret, tampered body, stale and future timestamp, replayed
Idempotency-Key, another tenant's key id, unknown and revoked key, a
request signed for another path);
tenant isolation with planted data; the secret never appears in logs, the
audit log, email_log or a later response; pushed injection text only
reaches the model inside a DATA block; the limits refuse with their codes;
the page cap refuses the page that would exceed it for every page-counted
source type; a URL change updates only the citation URL; first sync
resumes from its cursor; reconcile returns exactly the missing, stale and
deleted sets; disconnect removes the documents and shows "disconnected";
dashboard and widget handle unknown enum values; the OpenAPI change is
additive. All proven by `backend/tests/test_push_ingestion.py`,
`test_page_cap.py`, `test_push_signing.py` and the dashboard tests, each
defence red-proofed.
12. (7.6.b) the plugin passes its own CI and sends nothing section 4
forbids.
13. (7.6.c, live, production) the plugin on www.softwareske.com connects
with a production key, the first sync indexes its published pages and
posts, an edit updates the answer within minutes, a trashed page is
removed, and the widget answers from pushed content with citations to the
real permalinks.

## 11. Open items

- **An indexed-document count on the `/checkin` response (later).** An
  additive field (`D-api-enum-additions-additive` and the additive OpenAPI
  rule) so a connector can show how many of its documents SamoMind has
  indexed. Not built; until it is, a connector shows its own count of
  documents it has sent, and the dashboard's site table shows the indexed
  count.
