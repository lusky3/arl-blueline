#!/usr/bin/env bash
# Deploy themes/blueline to local (ddev), staging, or production.
#   ./scripts/deploy-theme.sh local
#   ./scripts/deploy-theme.sh staging
#   ./scripts/deploy-theme.sh production   (asks for confirmation)
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
[ -f "$SCRIPT_DIR/lib/hosts.env" ] && source "$SCRIPT_DIR/lib/hosts.env"

TARGET="${1:-}"
SRC="$(cd "$SCRIPT_DIR/.." && pwd)/themes/blueline/"

# What ships is defined once, in themes/blueline/.distignore (rsync syntax),
# which the release zip (scripts/release/package.sh) reads too -- see its
# header for why tools/ and assets/src/js/ must NOT be excluded.
# --delete-excluded removes anything excluded there that an earlier deploy
# already shipped.
[ -f "$SRC/.distignore" ] || { echo "missing $SRC.distignore" >&2; exit 1; }
EXCLUDES=( --exclude-from "$SRC/.distignore" --delete-excluded )

case "$TARGET" in
  local)
    # No ssh, no chown -- this is a plain directory on the same machine the
    # ddev project (arl-local, http://arl-local.ddev.site) mounts straight
    # from, owned by the invoking user already. Overridable via
    # BLUELINE_LOCAL_DEST for a differently-located/named ddev checkout.
    DEST="${BLUELINE_LOCAL_DEST:-$HOME/arl-local/wp-content/themes/blueline}/"
    mkdir -p "$DEST"
    rsync -az --delete "${EXCLUDES[@]}" "$SRC" "$DEST"
    echo "deployed local ($DEST)"
    exit 0
    ;;
  staging)
    HOST="${BLUELINE_STAGING_HOST:?set BLUELINE_STAGING_HOST (see scripts/lib/hosts.env.example)}"
    PORT="${BLUELINE_STAGING_PORT:?set BLUELINE_STAGING_PORT (see scripts/lib/hosts.env.example)}"
    DEST="/var/lib/docker/volumes/staging_wp_data/_data/wp-content/themes/blueline/"
    ;;
  production)
    HOST="${BLUELINE_PRODUCTION_HOST:?set BLUELINE_PRODUCTION_HOST (see scripts/lib/hosts.env.example)}"
    PORT="${BLUELINE_PRODUCTION_PORT:?set BLUELINE_PRODUCTION_PORT (see scripts/lib/hosts.env.example)}"
    DEST="/var/www/rookiehockey.ca/htdocs/wp-content/themes/blueline/"
    read -r -p "Deploy to PRODUCTION? type 'yes': " c; [ "$c" = "yes" ] || { echo "aborted"; exit 1; }
    ;;
  *) echo "usage: $0 {local|staging|production}" >&2; exit 2 ;;
esac

ssh -p "$PORT" "$HOST" "mkdir -p '$DEST'"
rsync -az --delete -e "ssh -p $PORT" "${EXCLUDES[@]}" "$SRC" "$HOST:$DEST"
ssh -p "$PORT" "$HOST" "chown -R 33:33 '$DEST'"

if [ "$TARGET" = "staging" ]; then
  # This rsync-based sync never triggers WordPress's after_switch_theme
  # hook (the theme is never actually "switched"), and the blueline-core
  # plugin's account-endpoints module only flushes after its own activation
  # or version bump, so a slug newly registered via add_rewrite_endpoint()
  # (e.g. that module's 'preferences') can 404 on staging until the rewrite
  # rules are flushed by hand. docker
  # exec against the staging-wp container mirrors the pattern
  # scripts/fetch-wp-core-oracle.sh already uses to reach staging's
  # WordPress container over this same SSH connection; -u 33 matches the
  # chown -R 33:33 above and the uid staging's own wp-cli runs as.
  # staging-wp has no wp-cli binary; /usr/local/bin/swp runs it in a sidecar.
  ssh -p "$PORT" "$HOST" "swp rewrite flush"
fi

echo "deployed $TARGET"
