#!/bin/sh
# Integration tests in wp-env with WooCommerce: the order status endpoints
# against real WordPress and real orders, once with WooCommerce's order tables
# (HPOS) off and once on.
#
# Run from the plugin root, after `composer install`: composer integration
# Clean up afterwards: npx @wordpress/env destroy
set -eu

WP_ENV="npx --yes @wordpress/env@10"
PLUGIN_DIR="wp-content/plugins/samomind-ai-assistant"

$WP_ENV start
# .wp-env.json lists both plugins, so wp-env has activated them.
$WP_ENV run tests-cli wp plugin list --status=active --field=name

for hpos in 0 1; do
	echo "== Integration tests, HPOS $hpos =="
	$WP_ENV run tests-cli --env-cwd="$PLUGIN_DIR" env SAMOMIND_HPOS="$hpos" php vendor/bin/phpunit -c phpunit-integration.xml.dist
done
