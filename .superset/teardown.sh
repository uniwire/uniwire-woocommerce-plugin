#!/usr/bin/env bash
# Runs when the workspace is deleted. Removes only this workspace's plugin copy.
#
# The ddev host is deliberately left running: it is shared with every other
# workspace, and stopping it here would pull the floor out from under them.
# Stop it by hand with `ddev stop` in the host directory when nothing needs it.

source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

if host_missing; then
	exit 0
fi

step "Removing $PLUGIN_SLUG from the host"

if host_running; then
	(cd "$CC_WOO_DIR" && ddev wp plugin deactivate "$PLUGIN_SLUG" >/dev/null 2>&1) || true
	say "deactivated"
else
	say "host is not running, removing files only"
fi

if [ -d "$PLUGIN_DIR" ]; then
	rm -rf "$PLUGIN_DIR"
	say "deleted $PLUGIN_DIR"
fi

say "host left running on purpose — it is shared between workspaces"
