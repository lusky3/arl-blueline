#!/usr/bin/env bash
# Deploy plugins/blueline-core to staging, make sure it is active, and verify it booted.
#   ./scripts/deploy-plugin.sh staging                full deploy: preflight, rsync, activate, verify
#   ./scripts/deploy-plugin.sh staging --skip-tests   same, without the local PHPUnit run
#   ./scripts/deploy-plugin.sh staging --verify       verify only: no preflight, no rsync, nothing written
# Production rollout is manual (owner's step, from the release zip: docs/RELEASING.md); this script refuses it.
#
# Preflight (local, before anything touches the server): `php -l` on every plugin file, the
# version sources agree (scripts/release/plugin-guard.php), and the plugin's PHPUnit suite passes
# (needs `composer install` in plugins/blueline-core; --skip-tests to bypass). An uncommitted
# plugin tree only warns, since deploying work in progress to staging is normal.
#
# Verification (read-only wp-cli through swp, which runs wp-cli in a sidecar container -- see
# scripts/deploy-theme.sh): the plugin is active, the deployed version equals the local header,
# and every module in the module list is loaded. A plugin held idle by a pre-1.1.0 theme is
# reported, not failed: that is the expected state after deploy-order step 1 (docs/OPERATIONS.md).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
[ -f "$SCRIPT_DIR/lib/hosts.env" ] && source "$SCRIPT_DIR/lib/hosts.env"

TARGET="${1:-}"
shift || true

VERIFY_ONLY=0
SKIP_TESTS=0
for flag in "$@"; do
  case "$flag" in
    --verify) VERIFY_ONLY=1 ;;
    --skip-tests) SKIP_TESTS=1 ;;
    *) echo "unknown option: $flag" >&2; echo "usage: $0 staging [--verify] [--skip-tests]" >&2; exit 2 ;;
  esac
done

ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
SRC="$ROOT/plugins/blueline-core/"

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
  *) echo "usage: $0 staging [--verify] [--skip-tests]" >&2; exit 2 ;;
esac

# The version the header declares: what the server must report after the deploy.
LOCAL_VERSION="$(sed -nE 's/^[ *]*Version:[[:space:]]*([^[:space:]]+).*/\1/p' "$SRC/blueline-core.php" | head -n1)"
[ -n "$LOCAL_VERSION" ] || { echo "preflight: cannot read the Version header in blueline-core.php" >&2; exit 1; }

preflight() {
  echo "preflight: blueline-core $LOCAL_VERSION"

  local file count=0
  while IFS= read -r -d '' file; do
    php -l "$file" >/dev/null || { echo "preflight: syntax error in ${file#"$ROOT"/}" >&2; exit 1; }
    count=$((count + 1))
  done < <(find "$SRC" -name '*.php' -not -path "$SRC"'vendor/*' -print0)
  echo "preflight: $count PHP files parse"

  php "$ROOT/scripts/release/plugin-guard.php" "" "$SRC" || exit 1

  if [ "$SKIP_TESTS" -eq 1 ]; then
    echo "preflight: PHPUnit skipped (--skip-tests)"
  elif [ -x "$SRC/vendor/bin/phpunit" ]; then
    (cd "$SRC" && vendor/bin/phpunit --no-progress) || { echo "preflight: PHPUnit failed; not deploying" >&2; exit 1; }
  else
    echo "preflight: composer dev dependencies are missing; run 'composer install' in plugins/blueline-core (or pass --skip-tests)" >&2
    exit 1
  fi

  if [ -n "$(git -C "$ROOT" status --porcelain -- plugins/blueline-core 2>/dev/null)" ]; then
    echo "preflight: warning: plugins/blueline-core has uncommitted changes; deploying them as they are" >&2
  fi
}

# Read-only checks on the server. Failing here does not undo the deploy; it says it did not take.
verify_remote() {
  echo "verify: $TARGET"

  ssh -p "$PORT" "$HOST" "swp plugin is-active blueline-core" >/dev/null 2>&1 \
    || { echo "verify: blueline-core is not active on $TARGET" >&2; return 1; }

  local remote_version
  remote_version="$(ssh -p "$PORT" "$HOST" "swp plugin get blueline-core --field=version" 2>/dev/null | tr -d '[:space:]')" \
    || { echo "verify: cannot read the deployed plugin version" >&2; return 1; }
  if [ "$remote_version" != "$LOCAL_VERSION" ]; then
    echo "verify: $TARGET reports version '$remote_version', expected '$LOCAL_VERSION'" >&2
    return 1
  fi
  echo "verify: active, version $remote_version"

  # The boot sentinel and loader are asked directly: the modules the list names must be the
  # modules that loaded (a skipped module otherwise fails silently). The PHP travels on stdin
  # to `swp eval-file -`, so no shell quoting is involved.
  ssh -p "$PORT" "$HOST" "swp eval-file -" <<'PHP' || { echo "verify: the plugin did not boot cleanly (see above)" >&2; return 1; }
<?php
if ( ! function_exists( 'blueline_core_boot' ) ) {
	echo "verify: blueline_core_boot() does not exist: the plugin file did not load\n";
	exit( 1 );
}
if ( blueline_core_legacy_theme_active() ) {
	echo "verify: plugin is idle: a pre-1.1.0 Blueline theme is active (expected before the theme update)\n";
	exit( 0 );
}
$wanted = array_keys( blueline_core_modules() );
$loaded = array_keys( blueline_core_loaded_modules() );
$absent = array_diff( $wanted, $loaded );
echo 'verify: ' . count( $loaded ) . '/' . count( $wanted ) . " modules loaded\n";
if ( array() !== $absent ) {
	echo 'verify: NOT LOADED: ' . implode( ', ', $absent ) . "\n";
	exit( 1 );
}
PHP
}

if [ "$VERIFY_ONLY" -eq 1 ]; then
  verify_remote
  echo "verified $TARGET"
  exit 0
fi

preflight

ssh -p "$PORT" "$HOST" "mkdir -p '$DEST'"
rsync -az --delete -e "ssh -p $PORT" "${EXCLUDES[@]}" "$SRC" "$HOST:$DEST"
ssh -p "$PORT" "$HOST" "chown -R 33:33 '$DEST'"

# Activation runs the plugin's activation hook (flags a rewrite flush for the
# next init). Skipped when already active so a redeploy stays idempotent.
# swp runs wp-cli in a sidecar container (see scripts/deploy-theme.sh).
ssh -p "$PORT" "$HOST" "swp plugin is-active blueline-core 2>/dev/null || swp plugin activate blueline-core"
ssh -p "$PORT" "$HOST" "swp cache flush"

verify_remote || { echo "deployed $TARGET, but verification FAILED" >&2; exit 1; }

echo "deployed $TARGET"
