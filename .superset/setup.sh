#!/usr/bin/env bash
# Runs once when a workspace is created. Must stay fast and idempotent, and must
# not require Docker: the WordPress host is started later by run.sh.

source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

step "Python tooling"
if command -v uv >/dev/null 2>&1; then
	(cd "$ROOT" && uv sync --quiet)
	say "venv ready — build zips with: uv run fab build-woo uniwire"
else
	warn "uv not found, skipping venv. Plugin builds will not work until it is installed."
fi

step "WordPress host"
if host_missing; then
	warn "host not found at $CC_WOO_DIR"
	warn "clone it, or set CC_WOO_DIR, then run ./.superset/run.sh"
	exit 0
fi

sync_plugin
say "code copied to $PLUGIN_SLUG"
say "start the host with: ./.superset/run.sh"
