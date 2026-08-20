#!/usr/bin/env bash
# Fetches wp-includes/{option,formatting,version}.php from staging's live
# WordPress core into themes/blueline/.wp-core-oracle/wp-includes/, so
# tests/WpCoreContractTest.php's oracle job (test_fixture_matches_a_live_wp_core_checkout)
# can run instead of skipping.
#
# Staging is the preferred oracle (see tests/fixtures/wp-core-option-contract.json's
# source.oracle) -- it is the version actually running production/staging, not a
# local guess. This script only ever reads three specific core PHP files over an
# existing SSH/docker path; it does not touch staging's database or options.
#
# Usage:
#   ./scripts/fetch-wp-core-oracle.sh
#   BLUELINE_WP_CORE_INCLUDES_DIR="$(pwd)/themes/blueline/.wp-core-oracle/wp-includes" \
#     npm --prefix themes/blueline run check:oracle
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
[ -f "$SCRIPT_DIR/lib/hosts.env" ] && source "$SCRIPT_DIR/lib/hosts.env"

HOST="${BLUELINE_STAGING_HOST:?set BLUELINE_STAGING_HOST (see scripts/lib/hosts.env.example)}"
PORT="${BLUELINE_STAGING_PORT:?set BLUELINE_STAGING_PORT (see scripts/lib/hosts.env.example)}"
CONTAINER="staging-wp"
CORE_DIR="/var/www/html/wp-includes"

DEST="$(cd "$SCRIPT_DIR/.." && pwd)/themes/blueline/.wp-core-oracle/wp-includes"
mkdir -p "$DEST"

ssh_exec() { ssh -p "$PORT" -o ConnectTimeout=10 "$HOST" "docker exec $CONTAINER $1" 2>/dev/null; }

echo "fetching wp-includes/{option,formatting,version}.php from staging ($HOST via $CONTAINER)..."
ssh_exec "cat $CORE_DIR/option.php" > "$DEST/option.php"
ssh_exec "cat $CORE_DIR/formatting.php" > "$DEST/formatting.php"
ssh_exec "cat $CORE_DIR/version.php" > "$DEST/version.php"

for f in option.php formatting.php version.php; do
  [ -s "$DEST/$f" ] || { echo "fetch failed: $DEST/$f is empty" >&2; exit 1; }
done

VERSION="$(grep -oP "(?<=\\\$wp_version = ')[^']+" "$DEST/version.php" || true)"
FIXTURE_VERSION="$(grep -oP '"wp_version":\s*"([^"]+)"' "$(dirname "${BASH_SOURCE[0]}")/../themes/blueline/tests/fixtures/wp-core-option-contract.json" | grep -oP '[0-9][0-9.]+' | head -1)"

echo "fetched staging WordPress $VERSION into $DEST"
if [ -n "$FIXTURE_VERSION" ] && [ "$VERSION" != "$FIXTURE_VERSION" ]; then
  echo "NOTE: staging is now $VERSION but the committed fixture claims source.wp_version=$FIXTURE_VERSION." >&2
  echo "      test_fixture_matches_a_live_wp_core_checkout() will fail (by design) until the fixture is" >&2
  echo "      regenerated with tests/tools/generate-wp-core-option-contract.php -- see that test's docblock." >&2
fi

echo
echo "run the oracle-backed gate with:"
echo "  BLUELINE_WP_CORE_INCLUDES_DIR=\"$DEST\" npm --prefix themes/blueline run check:oracle"
