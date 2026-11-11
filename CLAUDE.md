# CLAUDE.md

Samomind AI Assistant, the WordPress connector for Samomind's push
ingestion API.

- Architecture and naming: `docs/ARCHITECTURE.md`. Follow it; do not invent
  a second convention.
- API contract: `docs/API_CONTRACT.md` (pinned copy of Samomind's
  `docs/ingest/PUSH_INGESTION_DESIGN.md`, commit in its first line). It is
  the authority for every request the plugin makes. To update it, copy the
  file again from Samomind's origin/main and change the header commit.
- Checks before every commit: `composer lint`, `composer compat`,
  `composer test`. Each commit is phpcs-clean and test-green on its own.

## Working rules

- PHP 7.4 syntax only: no `match`, no named arguments, no enums, no
  `readonly`, no union types, no nullsafe operator, no constructor property
  promotion.
- Every output escaped, every input sanitised, a nonce and a capability
  check on every admin action (`manage_options`).
- The secret is never echoed, logged, or sent anywhere but as the HMAC key
  for requests to the API.
- No outbound calls except to the configured API base URL. No telemetry.
- Quote every heredoc (`<<'EOF'`).
- Never stop processes by pattern (`pkill -f`, `killall`); stop them by PID
  or with the tool that started them.
- Docker: clean up after wp-env (`npx wp-env destroy`, prune what it
  built). Stop if the disk has under 15 GB free.
- The reference plugin `onsite-training-cost-estimator` is read-only. Never
  edit it.
- Do not commit or push unless asked.
