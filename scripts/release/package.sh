#!/usr/bin/env bash
# Build the release zip: blueline-<version>.zip with a single top-level blueline/ folder.
#   scripts/release/package.sh <tag> <outdir>
#
# Source: `git archive` of <tag>, so only committed files ship (an untracked file
# in the checkout cannot leak into a release) and assets/dist/ is the committed
# build. BLUELINE_PACKAGE_FROM_WORKTREE=1 packages the working tree instead; the
# test suite and local dry runs use it, a release never does.
#
# What ships comes from themes/blueline/.distignore -- the same file
# scripts/deploy-theme.sh uses -- applied by rsync so both agree exactly.
#
# Reproducible: entries are sorted, owner/permissions/mtimes are normalised
# (SOURCE_DATE_EPOCH, defaulting to the packaged commit's time), so the same
# tag always yields the same bytes. No network.
set -euo pipefail

TAG="${1:?usage: $0 <tag> <outdir>}"
OUT="${2:?usage: $0 <tag> <outdir>}"
VERSION="${TAG#v}"

fail() {
	echo "package: $*" >&2
	exit 1
}

for tool in git rsync tar zip unzip find sort; do
	command -v "$tool" >/dev/null 2>&1 || fail "$tool is required"
done

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
mkdir -p "$OUT"
OUT="$(cd "$OUT" && pwd)"
ZIP="$OUT/blueline-$VERSION.zip"

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

if [ "${BLUELINE_PACKAGE_FROM_WORKTREE:-}" = "1" ]; then
	SRC="$ROOT/themes/blueline"
	EPOCH_REF="HEAD"
else
	git -C "$ROOT" rev-parse --verify --quiet "$TAG^{commit}" >/dev/null ||
		fail "tag $TAG not found (set BLUELINE_PACKAGE_FROM_WORKTREE=1 to package the working tree)"
	SRC="$STAGE/src"
	mkdir -p "$SRC"
	git -C "$ROOT" archive --format=tar "$TAG:themes/blueline" | tar -x -C "$SRC"
	EPOCH_REF="$TAG"
fi

rsync -a --exclude-from="$SRC/.distignore" "$SRC/" "$STAGE/blueline/"

if [ -z "${SOURCE_DATE_EPOCH:-}" ]; then
	SOURCE_DATE_EPOCH="$(git -C "$ROOT" log -1 --format=%ct "$EPOCH_REF" 2>/dev/null || true)"
	[[ "$SOURCE_DATE_EPOCH" =~ ^[0-9]+$ ]] || SOURCE_DATE_EPOCH=1767225600
fi
find "$STAGE/blueline" -type d -exec chmod 755 {} +
find "$STAGE/blueline" -type f -exec chmod 644 {} +
find "$STAGE/blueline" -exec touch -h -d "@$SOURCE_DATE_EPOCH" {} +

rm -f "$ZIP"
(cd "$STAGE" && find blueline -print | LC_ALL=C sort | TZ=UTC zip -q -X "$ZIP" -@)

listing="$(unzip -Z1 "$ZIP")"

# Must be inside: the runtime files a mis-scoped exclude would silently drop.
for need in blueline/style.css blueline/screenshot.png blueline/functions.php blueline/assets/dist/index.js \
	blueline/assets/src/js/settings-photos.js blueline/assets/src/js/settings-brand-colors.js \
	blueline/assets/src/js/settings-occasions.js blueline/tools/contrast-rules.json blueline/tools/tokens.json \
	blueline/assets/dist/fonts/inter-variable.woff2 blueline/assets/fonts/OFL-Inter.txt \
	blueline/assets/fonts/OFL-BarlowCondensed.txt; do
	grep -qxF "$need" <<<"$listing" || fail "$need is missing from the zip"
done

# Must be outside: dev, test and tooling files, and build inputs already bundled into assets/dist/.
for forbidden in blueline/tests/ blueline/node_modules/ blueline/vendor/ blueline/composer.json \
	blueline/package.json blueline/.distignore blueline/tools/lib/ blueline/tools/check-contrast.mjs \
	blueline/assets/src/css/ blueline/assets/src/js/index.js blueline/assets/fonts/inter-variable.woff2; do
	if grep -q "^$forbidden" <<<"$listing"; then
		fail "$forbidden must not ship"
	fi
done

# Nothing outside the blueline/ root.
if grep -qv '^blueline/' <<<"$listing"; then
	fail "entries outside blueline/ found"
fi

# The version inside the zip is the tag's.
if ! unzip -p "$ZIP" blueline/style.css | grep -qE "^Version:[[:space:]]*${VERSION//./\\.}[[:space:]]*\$"; then
	fail "style.css Version in the zip does not match $VERSION"
fi

echo "$ZIP"
