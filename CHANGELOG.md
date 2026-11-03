# Changelog

## Unreleased

- `docs/API_CONTRACT.md` refreshed to SamoMind `ba44704` (7.6.a1: deactivation
  does nothing server-side, a dashboard disconnect wins, indexed count is an
  open item). No change to the plugin's behaviour.
- readme.txt: FAQ rewritten around the five questions a site owner asks
  first, a Screenshots section (images to come, see `.wordpress-org/`), and
  External services moved under Description so the sections keep the
  WordPress.org order.
- Development: Plugin Check static rules (`composer plugin-check`), the
  translation template, `composer build` for the release zip and a Release
  workflow on `v*` tags. `load_plugin_textdomain` is no longer called
  (WordPress loads WordPress.org translations itself).

## 0.1.0 (2026-11-03)

First release, for the pilot (step 7.6.b). Built against SamoMind's push
ingestion contract at commit `98d55ccc0091a4bfb013302b5a82cf29b50bd872`
(`docs/API_CONTRACT.md`).

- Settings: API base URL, key id, write-only secret (or the
  `SAMOMIND_API_SECRET` constant), widget embed snippet.
- Signed requests (contract 5.2) with the pinned worked example as a test
  vector.
- Connect and disconnect; status page with connection, sync progress and a
  200-row request log.
- Post type checklist on the settings page (all ticked by default); unticking
  a type removes its items from SamoMind.
- Deactivation sends nothing and keeps the connection; uninstall disconnects.
- A disconnect from the SamoMind dashboard wins: the plugin stops and waits
  for an admin to click Connect.
- Full sync in the background (Action Scheduler or WP-Cron), resumable from
  the server's cursor, stopping on the plan's page cap.
- Pushes on publish and update, deletes on trash, unpublish, private,
  password and delete; deterministic idempotency keys per event.
- Daily check-in and reconcile; retries with the contract's backoff;
  admin notice after 24 hours of failures.
- Widget snippet in the footer, accepted only from widget.samomind.com.
- WP-CLI: `wp samomind status|connect|disconnect|sync|reconcile|checkin`.
- Uninstall disconnects a connected site, then removes all options,
  transients and scheduled events.
