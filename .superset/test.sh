#!/usr/bin/env bash
# Runs the WP-CLI regression suites against this workspace's plugin copy.
# Pass a suite name to run just one, e.g. ./.superset/test.sh payment-complete

source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

if ! host_running; then
	warn "host is not running — start it with ./.superset/run.sh"
	exit 1
fi

sync_plugin

suites=(test-stuck-order-recovery test-payment-complete test-amount-mismatch test-webhook-signature test-invoice-update)
if [ $# -gt 0 ]; then
	suites=("test-${1#test-}")
fi

failed=0
for suite in "${suites[@]}"; do
	step "$suite"
	if (cd "$CC_WOO_DIR" && ddev wp eval-file "/var/www/html/web/app/plugins/$PLUGIN_SLUG/tests/$suite.php"); then
		:
	else
		failed=$((failed + 1))
	fi
done

if [ "$failed" -gt 0 ]; then
	printf '\n%s suite(s) failed\n' "$failed" >&2
	exit 1
fi

printf '\nall suites passed\n'
