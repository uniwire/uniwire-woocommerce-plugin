#!/usr/bin/env bash
# Brings the workspace up: starts the shared WordPress host, copies this
# worktree in as its own plugin, activates it, then keeps the copy in step with
# your edits until you stop it.
#
# Set WC_DEV_WATCH=0 to bring everything up and exit instead of watching.

source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

if host_missing; then
	warn "host not found at $CC_WOO_DIR — nothing to run"
	exit 1
fi

step "WordPress host"
if host_running; then
	say "already running"
else
	say "starting ddev (first start takes a while)"
	(cd "$CC_WOO_DIR" && ddev start >/dev/null)
fi

step "Plugin"
sync_plugin
activate_plugin
say "active as $PLUGIN_SLUG"

step "Ready"
say "site:  https://cc-woo.local.adventure.lv:445"
say "admin: https://cc-woo.local.adventure.lv:445/wp/wp-admin/"
say "tests: ./.superset/test.sh"

if [ "${WC_DEV_WATCH:-1}" = "0" ]; then
	exit 0
fi

step "Watching for changes"
say "edits are copied into the host every 2s — Ctrl+C to stop"
say "the host keeps running afterwards; it is shared with other workspaces"
while true; do
	sleep 2
	sync_plugin
done
