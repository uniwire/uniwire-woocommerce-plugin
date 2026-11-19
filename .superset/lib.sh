#!/usr/bin/env bash
# Shared helpers for the Superset workspace lifecycle scripts.
#
# This repo is a plugin template: it renders nothing on its own and needs a
# WordPress host to run inside. That host is a separate ddev project which is
# SHARED between all workspaces, so every script here is careful to touch only
# its own plugin directory and to never stop the host out from under someone.

set -euo pipefail

# Repo root, whichever worktree we happen to be in.
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# The WordPress host lives beside the main checkout of this repo. Locating it
# through git rather than $HOME keeps this correct from a worktree, whose path
# is nowhere near the main checkout, and under tools that rewrite $HOME.
default_host_dir() {
	local common parent
	common="$(git -C "$ROOT" rev-parse --git-common-dir 2>/dev/null)" || return 1
	case "$common" in
	/*) ;;
	*) common="$ROOT/$common" ;;
	esac
	parent="$(cd "$common/../.." 2>/dev/null && pwd)" || return 1
	printf '%s/cc-woo' "$parent"
}

# Override with the CC_WOO_DIR environment variable if your host lives elsewhere.
CC_WOO_DIR="${CC_WOO_DIR:-$(default_host_dir || printf '%s' "$HOME/_REPO/cryptochill/cc-woo")}"
PLUGINS_DIR="$CC_WOO_DIR/web/app/plugins"

# One plugin directory per workspace, named after the worktree, so two open
# workspaces cannot overwrite each other's code inside the shared host.
WORKSPACE_NAME="$(basename "$ROOT" | tr '[:upper:]' '[:lower:]' | tr -c 'a-z0-9-' '-' | sed 's/-\{2,\}/-/g; s/^-//; s/-$//')"
PLUGIN_SLUG="wc-dev-${WORKSPACE_NAME:-workspace}"
PLUGIN_DIR="$PLUGINS_DIR/$PLUGIN_SLUG"

say() { printf '  %s\n' "$*"; }
step() { printf '\n▶ %s\n' "$*"; }
warn() { printf '  ! %s\n' "$*" >&2; }

host_missing() {
	[ ! -d "$CC_WOO_DIR" ]
}

# True only when the ddev host is up and answering.
host_running() {
	command -v ddev >/dev/null 2>&1 &&
		(cd "$CC_WOO_DIR" && ddev exec true >/dev/null 2>&1)
}

# Copy this worktree into its own plugin folder inside the host. Excludes keep
# the copy small and stop the host from ever seeing our git history or venv.
sync_plugin() {
	mkdir -p "$PLUGIN_DIR"
	rsync -a --delete \
		--exclude '.git' \
		--exclude '.venv' \
		--exclude '.claude' \
		--exclude 'dist' \
		--exclude 'node_modules' \
		"$ROOT/" "$PLUGIN_DIR/"
}

# Leave exactly one gateway plugin active: ours. Every folder matched here
# registers the same WooCommerce gateway, so they cannot coexist.
activate_plugin() {
	local active name
	active="$(cd "$CC_WOO_DIR" && ddev wp plugin list --field=name --status=active 2>/dev/null || true)"
	for name in $active; do
		case "$name" in
		"$PLUGIN_SLUG") continue ;;
		wc-dev-* | cryptochill-woocommerce-plugin | uniwire-woocommerce-plugin | woocommerce-plugin)
			(cd "$CC_WOO_DIR" && ddev wp plugin deactivate "$name" >/dev/null 2>&1) || true
			say "deactivated $name"
			;;
		esac
	done
	(cd "$CC_WOO_DIR" && ddev wp plugin activate "$PLUGIN_SLUG" >/dev/null 2>&1) ||
		warn "could not activate $PLUGIN_SLUG — check 'ddev wp plugin list'"
}
