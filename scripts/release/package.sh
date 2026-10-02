#!/usr/bin/env bash
# Build the release zip: blueline-<version>.zip with a single top-level blueline/ folder.
#   scripts/release/package.sh <tag> <outdir>
# What ships comes from themes/blueline/.distignore -- the same file
# scripts/deploy-theme.sh uses -- applied by rsync so both agree exactly.
# Run `npm run build` first: assets/dist/ is zipped as it stands. No network.
set -euo pipefail

TAG="${1:?usage: $0 <tag> <outdir>}"
OUT="${2:?usage: $0 <tag> <outdir>}"
VERSION="${TAG#v}"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
THEME="$ROOT/themes/blueline"
mkdir -p "$OUT"
OUT="$(cd "$OUT" && pwd)"
ZIP="$OUT/blueline-$VERSION.zip"

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

rsync -a --exclude-from="$THEME/.distignore" "$THEME/" "$STAGE/blueline/"
rm -f "$ZIP"
(cd "$STAGE" && zip -qr -X "$ZIP" blueline)

listing="$(unzip -Z1 "$ZIP")"

# Must be inside: the runtime files a mis-scoped exclude would silently drop.
for need in blueline/style.css blueline/functions.php blueline/assets/dist/index.js \
	blueline/assets/src/js/ blueline/tools/contrast-rules.json; do
	if ! grep -qxF "$need" <<<"$listing" && ! grep -q "^$need" <<<"$listing"; then
		echo "package: $need is missing from the zip" >&2
		exit 1
	fi
done

# Must be outside: dev, test and tooling files.
for forbidden in blueline/tests/ blueline/node_modules/ blueline/vendor/ blueline/composer.json \
	blueline/package.json blueline/.distignore; do
	if grep -q "^$forbidden" <<<"$listing"; then
		echo "package: $forbidden must not ship" >&2
		exit 1
	fi
done

# Nothing outside the blueline/ root.
if grep -qv '^blueline/' <<<"$listing"; then
	echo "package: entries outside blueline/ found" >&2
	exit 1
fi

# The version inside the zip is the tag's.
if ! unzip -p "$ZIP" blueline/style.css | grep -qE "^Version:[[:space:]]*${VERSION//./\\.}[[:space:]]*\$"; then
	echo "package: style.css Version in the zip does not match $VERSION" >&2
	exit 1
fi

echo "$ZIP"
