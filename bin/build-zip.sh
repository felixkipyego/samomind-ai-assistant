#!/bin/sh
# Build build/samomind-ai-assistant-<version>.zip, the file WordPress
# installs. It packs the committed tree (HEAD), so uncommitted changes and
# untracked files never end up in a release, and leaves out every path in
# .distignore. The zip holds one folder named after the slug.
set -eu

cd "$(dirname "$0")/.."
ROOT=$(pwd)
SLUG=samomind-ai-assistant

VERSION=$(sed -n 's/^ \* Version: *//p' "$SLUG.php" | tr -d '[:space:]')
STABLE=$(sed -n 's/^Stable tag: *//p' readme.txt | tr -d '[:space:]')
if [ -z "$VERSION" ]; then
	echo "No Version header in $SLUG.php" >&2
	exit 1
fi
if [ "$VERSION" != "$STABLE" ]; then
	echo "Plugin header Version ($VERSION) and readme.txt Stable tag ($STABLE) differ" >&2
	exit 1
fi
if [ -n "$(git status --porcelain)" ]; then
	echo "Note: uncommitted changes are not included; the zip is built from HEAD." >&2
fi

WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT INT TERM

mkdir "$WORK/src" "$WORK/$SLUG"
git archive --format=tar HEAD | tar -x -C "$WORK/src"
rsync -a --exclude-from="$WORK/src/.distignore" "$WORK/src/" "$WORK/$SLUG/"

mkdir -p build
ZIP="$ROOT/build/$SLUG-$VERSION.zip"
rm -f "$ZIP"
(cd "$WORK" && zip -q -r -X "$ZIP" "$SLUG")

echo "$ZIP"
