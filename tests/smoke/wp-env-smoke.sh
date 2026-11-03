#!/bin/sh
# Smoke test in wp-env (Docker): activate the plugin, open the settings and
# status pages as the admin, run `wp samomind status`, and check the debug
# log has nothing from the plugin. Calls no SamoMind API.
#
# Run from the plugin root: composer smoke
# Clean up afterwards: npx @wordpress/env destroy
set -eu

WP_ENV="npx --yes @wordpress/env@10"
BASE="http://localhost:8888"
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

$WP_ENV start
$WP_ENV run cli wp plugin activate samomind-ai-assistant
$WP_ENV run cli wp samomind status

# Log in as the default wp-env admin and fetch both admin pages.
curl -sf -o /dev/null -c "$WORK/jar" -b "$WORK/jar" \
	--cookie 'wordpress_test_cookie=WP%20Cookie%20check' \
	--data 'log=admin&pwd=password&testcookie=1' "$BASE/wp-login.php"

for page in samomind-settings samomind-status; do
	curl -sf -b "$WORK/jar" -o "$WORK/$page.html" "$BASE/wp-admin/admin.php?page=$page"
	if ! grep -q 'samomind-admin-wrap' "$WORK/$page.html"; then
		echo "FAIL: $page did not render" >&2
		exit 1
	fi
	echo "ok: $page renders"
done

if $WP_ENV run cli sh -c 'grep -i samomind wp-content/debug.log 2>/dev/null'; then
	echo "FAIL: the debug log mentions the plugin" >&2
	exit 1
fi
echo "ok: debug log clean"
echo "Smoke passed."
