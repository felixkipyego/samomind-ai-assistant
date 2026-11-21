#!/bin/sh
# Update test: install an older release zip and set the site up, replace its
# files with the new release zip the way WordPress does (the plugin stays
# active, the activation hook does NOT run), then check that keys stay, the
# admin pages load and a sync runs. Nothing leaves the test site: the
# Samomind API is mocked.
#
# Run from the plugin root, after `composer build`:
#   sh tests/smoke/upgrade-from-zip.sh ../New/samomind-ai-assistant-0.3.5.zip build/samomind-ai-assistant-0.4.0.zip
#
# It makes its own wp-env project in a temporary folder (not this repo) and
# destroys it when it ends. Stop the repo's own wp-env first: both want the
# same ports.
set -eu

if [ "$#" -ne 2 ]; then
	echo "Usage: sh tests/smoke/upgrade-from-zip.sh OLD.zip NEW.zip" >&2
	exit 2
fi

HERE=$(cd "$(dirname "$0")" && pwd)
OLD_ZIP=$(cd "$(dirname "$1")" && pwd)/$(basename "$1")
NEW_ZIP=$(cd "$(dirname "$2")" && pwd)/$(basename "$2")
WORK=$(mktemp -d "${TMPDIR:-/tmp}/samomind-upgrade.XXXXXX")
WP_ENV="npx --yes @wordpress/env@10"
SLUG="samomind-ai-assistant"

cleanup() {
	cd "$WORK" && echo y | $WP_ENV destroy >/dev/null 2>&1 || true
	cd / && rm -rf "$WORK"
}
trap cleanup EXIT

# Run wp in the tests site, without the npm engine warnings, keeping the exit status.
wp() {
	if $WP_ENV run tests-cli "$@" >"$WORK/out" 2>&1; then status=0; else status=$?; fi
	grep -v "EBADENGINE\|npm warn\|^[[:space:]]*package:\|^[[:space:]]*required:\|^[[:space:]]*current:\|^}\|^$\|^ℹ\|^✔" "$WORK/out" || true
	return "$status"
}

echo "== Install the old release"
unzip -q "$OLD_ZIP" -d "$WORK"
mkdir -p "$WORK/smoke"
cp "$HERE"/upgrade/*.php "$WORK/smoke/"
cat >"$WORK/.wp-env.json" <<'JSON'
{
  "core": null,
  "plugins": [
    "./samomind-ai-assistant",
    "https://downloads.wordpress.org/plugin/woocommerce.11.3.0.zip"
  ],
  "mappings": { "smoke": "./smoke" },
  "config": { "WP_DEBUG": true, "WP_DEBUG_LOG": true, "WP_DEBUG_DISPLAY": false }
}
JSON
cd "$WORK"
$WP_ENV start >"$WORK/start.log" 2>&1 || { tail -5 "$WORK/start.log"; exit 1; }

echo "old version: $(wp plugin get "$SLUG" --field=version)"
wp plugin list --name="$SLUG" --field=status | sed 's/^/old status: /'

echo "== Step 1: a connected site on the old release"
wp eval-file smoke/before.php

echo "== Replace the files, as an update does (no activation)"
wp sh -c 'rm -f /var/www/html/wp-content/debug.log' >/dev/null
UNPACKED="$WORK/new"
mkdir -p "$UNPACKED"
unzip -q "$NEW_ZIP" -d "$UNPACKED"
# Replace what is inside the folder, not the folder: Docker mounts that very folder.
find "${WORK:?}/$SLUG" -mindepth 1 -delete
cp -R "$UNPACKED/$SLUG/." "$WORK/$SLUG/"
echo "new version: $(wp plugin get "$SLUG" --field=version)"
wp plugin list --name="$SLUG" --field=status | sed 's/^/new status: /'

echo "== Step 2: the same site on the new release"
wp eval-file smoke/after.php

echo "== Errors in the debug log that name this plugin"
wp eval 'echo file_exists( WP_CONTENT_DIR . "/debug.log" ) ? file_get_contents( WP_CONTENT_DIR . "/debug.log" ) : "";' >"$WORK/log.txt" || true
if grep -i "samomind\|fatal" "$WORK/log.txt" | grep -v "^ok" >/dev/null 2>&1; then
	grep -i "samomind\|fatal" "$WORK/log.txt" | head -20
	echo "FAIL  the debug log has errors from this plugin"
	exit 1
fi
echo "ok    none (other lines in the log: $(grep -c . "$WORK/log.txt" || true))"
echo "UPDATE TEST PASSED"
