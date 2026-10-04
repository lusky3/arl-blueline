#!/usr/bin/env bash
# Build the blueline-core release zip: blueline-core-<version>.zip with a single
# top-level blueline-core/ folder, plus blueline-core-<version>.zip.sha256.
#   scripts/release/package-plugin.sh <outdir> [expected-version]
#
# The version is the plugin's own (blueline-core.php "Version:"), independent of
# the theme tag -- see docs/RELEASING.md. Pass expected-version (no leading v) to
# insist on one. What ships comes from plugins/blueline-core/.distignore, the same
# file scripts/deploy-plugin.sh uses, applied by rsync so both agree exactly.
#
# Reproducible: entries are sorted, owner/permissions/mtimes are normalised
# (SOURCE_DATE_EPOCH, defaulting to the last commit touching the plugin), so the
# same tree always yields the same bytes. No network, except `composer install
# --no-dev` when (and only when) the plugin declares runtime dependencies.
#
# Env: BLUELINE_PLUGIN_DIR overrides the source tree (used by the self-test).
set -euo pipefail

OUT="${1:?usage: $0 <outdir> [expected-version]}"
EXPECTED="${2:-}"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PLUGIN="$(cd "${BLUELINE_PLUGIN_DIR:-$ROOT/plugins/blueline-core}" && pwd)"
SLUG="blueline-core"

fail() {
	echo "package-plugin: $*" >&2
	exit 1
}

for tool in php rsync zip unzip find sort sha256sum; do
	command -v "$tool" >/dev/null 2>&1 || fail "$tool is required"
done
[ -f "$PLUGIN/blueline-core.php" ] || fail "$PLUGIN/blueline-core.php not found"
[ -f "$PLUGIN/.distignore" ] || fail "$PLUGIN/.distignore not found"

# Version sources must agree (header, constant, composer.json, readme stable tag).
php "$ROOT/scripts/release/plugin-guard.php" "$EXPECTED" "$PLUGIN"

VERSION="$(sed -nE 's/^[ *]*Version:[[:space:]]*([^[:space:]]+).*/\1/p' "$PLUGIN/blueline-core.php" | head -n1)"
[ -n "$VERSION" ] || fail "cannot read the Version header"

mkdir -p "$OUT"
OUT="$(cd "$OUT" && pwd)"
ZIP="$OUT/$SLUG-$VERSION.zip"

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

rsync -a --exclude-from="$PLUGIN/.distignore" "$PLUGIN/" "$STAGE/$SLUG/"

# Runtime composer dependencies (anything beyond php / ext-* / lib-*) must ship in
# vendor/. The plugin has none today, so vendor/ is dev tooling and stays out.
# shellcheck disable=SC2016 # the single-quoted body is PHP, not shell
HAS_RUNTIME_DEPS="$(php -r '
	$c = is_file( $argv[1] ) ? json_decode( (string) file_get_contents( $argv[1] ), true ) : array();
	$n = 0;
	foreach ( (array) ( $c["require"] ?? array() ) as $pkg => $unused ) {
		if ( ! preg_match( "/^(php|ext-.+|lib-.+|php-.+)$/", (string) $pkg ) ) {
			++$n;
		}
	}
	echo $n > 0 ? "1" : "0";
' "$PLUGIN/composer.json")"

if [ "$HAS_RUNTIME_DEPS" = "1" ]; then
	command -v composer >/dev/null 2>&1 || fail "composer is required: the plugin declares runtime dependencies"
	cp "$PLUGIN/composer.json" "$STAGE/$SLUG/composer.json"
	[ -f "$PLUGIN/composer.lock" ] && cp "$PLUGIN/composer.lock" "$STAGE/$SLUG/composer.lock"
	composer install --no-dev --no-interaction --no-progress --prefer-dist --classmap-authoritative \
		--working-dir="$STAGE/$SLUG" >&2
	rm -f "$STAGE/$SLUG/composer.json" "$STAGE/$SLUG/composer.lock"
	rm -rf "$STAGE/$SLUG/vendor/bin"
fi

# Every shipped PHP file must parse.
while IFS= read -r -d '' file; do
	php -l "$file" >/dev/null || fail "syntax error in ${file#"$STAGE"/}"
done < <(find "$STAGE/$SLUG" -name '*.php' -print0 | sort -z)

# Reproducibility: fixed mtimes, owner-agnostic permissions, sorted entries.
if [ -z "${SOURCE_DATE_EPOCH:-}" ]; then
	SOURCE_DATE_EPOCH="$(git -C "$ROOT" log -1 --format=%ct -- plugins/blueline-core 2>/dev/null || true)"
	[[ "$SOURCE_DATE_EPOCH" =~ ^[0-9]+$ ]] || SOURCE_DATE_EPOCH=1767225600
fi
find "$STAGE/$SLUG" -type d -exec chmod 755 {} +
find "$STAGE/$SLUG" -type f -exec chmod 644 {} +
find "$STAGE/$SLUG" -exec touch -h -d "@$SOURCE_DATE_EPOCH" {} +

rm -f "$ZIP" "$ZIP.sha256"
(cd "$STAGE" && find "$SLUG" -print | LC_ALL=C sort | TZ=UTC zip -q -X "$ZIP" -@)

listing="$(unzip -Z1 "$ZIP")"

# Must be inside: the entry points, and every module modules.php lists. A mis-scoped
# exclude would otherwise ship a plugin whose loader skips modules silently.
for need in "$SLUG/blueline-core.php" "$SLUG/uninstall.php" "$SLUG/includes/boot.php" "$SLUG/includes/modules.php"; do
	grep -qxF "$need" <<<"$listing" || fail "$need is missing from the zip"
done
modules_found=0
while IFS= read -r rel; do
	modules_found=$((modules_found + 1))
	grep -qxF "$SLUG/includes/$rel" <<<"$listing" || fail "module file includes/$rel (listed in modules.php) is missing from the zip"
done < <(grep -oE "=>[[:space:]]*'[^']+'" "$PLUGIN/includes/modules.php" | sed -E "s/^=>[[:space:]]*'([^']+)'/\1/")
[ "$modules_found" -gt 0 ] || fail "found no modules in includes/modules.php"

# Must be outside: dev, test and tooling files.
for forbidden in tests/ .github/ .git .gitignore .distignore composer.json composer.lock phpunit.xml \
	phpcs.xml.dist .phpunit.result.cache; do
	if grep -qE "^$SLUG/${forbidden//./\\.}" <<<"$listing"; then
		fail "$forbidden must not ship"
	fi
done
if [ "$HAS_RUNTIME_DEPS" != "1" ] && grep -q "^$SLUG/vendor" <<<"$listing"; then
	fail "vendor/ must not ship: the plugin has no runtime dependencies"
fi

# Allowlist: every entry is a known runtime file type. A new kind of file (JSON data,
# .mo files, images ...) fails here until it is added deliberately.
allowed="^$SLUG/(blueline-core\\.php|uninstall\\.php|README\\.md|readme\\.txt|LICENSE(\\.txt)?"
allowed="$allowed|(includes|templates)/.+\\.php|languages/.+\\.(mo|po|pot|json)|assets/.+\\.(css|js|png|jpe?g|gif|svg|webp|woff2?))\$"
if [ "$HAS_RUNTIME_DEPS" = "1" ]; then
	allowed="${allowed%)\$}|vendor/.+)\$"
fi
unexpected="$(grep -vE '/$' <<<"$listing" | grep -vE "$allowed" || true)"
[ -z "$unexpected" ] || fail "unexpected files in the zip (extend the allowlist in package-plugin.sh if intentional):
$unexpected"

# Nothing outside the blueline-core/ root.
if grep -qv "^$SLUG/" <<<"$listing"; then
	fail "entries outside $SLUG/ found"
fi

# The version inside the zip is the one we named the file for.
if ! unzip -p "$ZIP" "$SLUG/blueline-core.php" | grep -qE "^[ *]*Version:[[:space:]]*${VERSION//./\\.}[[:space:]]*\$"; then
	fail "Version header in the zip does not match $VERSION"
fi

(cd "$OUT" && sha256sum "$(basename "$ZIP")" >"$(basename "$ZIP").sha256")

echo "$ZIP"
