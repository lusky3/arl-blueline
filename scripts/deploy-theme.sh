#!/usr/bin/env bash
# Deploy themes/blueline to local (ddev), staging, or production.
#   ./scripts/deploy-theme.sh local
#   ./scripts/deploy-theme.sh staging
#   ./scripts/deploy-theme.sh production   (asks for confirmation)
set -euo pipefail

TARGET="${1:-}"
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/themes/blueline/"

# NOTE: tools/ is intentionally NOT excluded below (all targets). It looks
# like a build-only directory, but inc/team-colors.php reads
# tools/contrast-rules.json at RUNTIME on every front-end team page
# (blueline_contrast_threshold()) to derive each team's readable foreground
# colour. Excluding tools/ here would not fail loudly -- the PHP falls back
# to hard-coded 4.5/3.0 AA thresholds and logs the fact (see
# blueline_contrast_rules_read_failure() in inc/team-colors.php) -- so a
# future tidy-up of this list that adds tools/ (or contrast-rules.json
# specifically) would silently degrade every team page's colour derivation
# on the deployed site with nothing in this script or its output telling
# you why.
EXCLUDES=(--exclude node_modules --exclude vendor --exclude tests --exclude .git --exclude '*.map')

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
    HOST="root@staging-host.example"; PORT=SSH_PORT
    DEST="/var/lib/docker/volumes/staging_wp_data/_data/wp-content/themes/blueline/"
    ;;
  production)
    HOST="root@production-host.example"; PORT=SSH_PORT
    DEST="/var/www/rookiehockey.ca/htdocs/wp-content/themes/blueline/"
    read -r -p "Deploy to PRODUCTION? type 'yes': " c; [ "$c" = "yes" ] || { echo "aborted"; exit 1; }
    ;;
  *) echo "usage: $0 {local|staging|production}" >&2; exit 2 ;;
esac

ssh -p "$PORT" "$HOST" "mkdir -p '$DEST'"
rsync -az --delete -e "ssh -p $PORT" "${EXCLUDES[@]}" "$SRC" "$HOST:$DEST"
ssh -p "$PORT" "$HOST" "chown -R 33:33 '$DEST'"
echo "deployed $TARGET"
