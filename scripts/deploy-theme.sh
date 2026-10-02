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
#
# assets/src/js/ must ship too: inc/settings/page.php enqueues
# settings-{photos,brand-colors,occasions}.js straight from source. Only
# assets/src/css/ (compiled into assets/dist/) is build-only.
#
# Leading-slash patterns are anchored to the theme root. --delete-excluded
# removes anything excluded here that an earlier deploy already shipped.
EXCLUDES=(
  --exclude node_modules --exclude vendor --exclude tests --exclude .git --exclude '*.map'
  --exclude /tests-e2e --exclude /tests-browser --exclude /artifacts --exclude /.wp-core-oracle
  --exclude /.phpunit.result.cache --exclude /phpunit.xml --exclude /.gitignore
  --exclude /composer.json --exclude /composer.lock
  --exclude /package.json --exclude /package-lock.json
  --exclude /playwright.config.js --exclude /webpack.config.js
  --exclude /assets/src/css --exclude '*.test.mjs'
  --delete-excluded
)

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
  # hook (the theme is never actually "switched" -- see
  # inc/account/endpoints.php's own `add_action( 'after_switch_theme',
  # 'flush_rewrite_rules' )`), so a slug this deploy newly registered via
  # add_rewrite_endpoint() (e.g. inc/account/endpoints.php's 'preferences')
  # 404s on staging until the rewrite rules are flushed by hand. docker
  # exec against the staging-wp container mirrors the pattern
  # scripts/fetch-wp-core-oracle.sh already uses to reach staging's
  # WordPress container over this same SSH connection; -u 33 matches the
  # chown -R 33:33 above and the uid staging's own wp-cli runs as.
  # staging-wp has no wp-cli binary; /usr/local/bin/swp runs it in a sidecar.
  ssh -p "$PORT" "$HOST" "swp rewrite flush"
fi

echo "deployed $TARGET"
