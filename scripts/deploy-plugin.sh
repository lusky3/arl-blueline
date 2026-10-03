#!/usr/bin/env bash
# Deploy plugins/blueline-core to staging and make sure it is active.
#   ./scripts/deploy-plugin.sh staging
# Production rollout is manual (owner's step); this script refuses it.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
[ -f "$SCRIPT_DIR/lib/hosts.env" ] && source "$SCRIPT_DIR/lib/hosts.env"

TARGET="${1:-}"
SRC="$(cd "$SCRIPT_DIR/.." && pwd)/plugins/blueline-core/"

# What ships is defined once, in plugins/blueline-core/.distignore (rsync syntax).
# --delete-excluded removes anything excluded there that an earlier deploy shipped.
[ -f "$SRC/.distignore" ] || { echo "missing $SRC.distignore" >&2; exit 1; }
EXCLUDES=( --exclude-from "$SRC/.distignore" --delete-excluded )

case "$TARGET" in
  staging)
    HOST="${BLUELINE_STAGING_HOST:?set BLUELINE_STAGING_HOST (see scripts/lib/hosts.env.example)}"
    PORT="${BLUELINE_STAGING_PORT:?set BLUELINE_STAGING_PORT (see scripts/lib/hosts.env.example)}"
    DEST="/var/lib/docker/volumes/staging_wp_data/_data/wp-content/plugins/blueline-core/"
    ;;
  production)
    echo "refusing: production rollout is manual" >&2
    exit 1
    ;;
  *) echo "usage: $0 staging" >&2; exit 2 ;;
esac

ssh -p "$PORT" "$HOST" "mkdir -p '$DEST'"
rsync -az --delete -e "ssh -p $PORT" "${EXCLUDES[@]}" "$SRC" "$HOST:$DEST"
ssh -p "$PORT" "$HOST" "chown -R 33:33 '$DEST'"

# Activation runs the plugin's activation hook (flags a rewrite flush for the
# next init). Skipped when already active so a redeploy stays idempotent.
# swp runs wp-cli in a sidecar container (see scripts/deploy-theme.sh).
ssh -p "$PORT" "$HOST" "swp plugin is-active blueline-core 2>/dev/null || swp plugin activate blueline-core"
ssh -p "$PORT" "$HOST" "swp cache flush"

echo "deployed $TARGET"
