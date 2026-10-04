#!/usr/bin/env bash
# Self-test for the plugin release pipeline: runs package-plugin.sh and
# plugin-guard.php against the working tree (nothing is published) and against
# deliberately broken copies, and asserts the zip's content list.
#   scripts/release/test-plugin-release.sh
# Run by .github/workflows/check.yml. Needs php, rsync, zip, unzip; composer is
# only needed for the runtime-dependency case (skipped without it).
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PKG="$ROOT/scripts/release/package-plugin.sh"
GUARD="$ROOT/scripts/release/plugin-guard.php"
PLUGIN="$ROOT/plugins/blueline-core"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

failures=0
pass() { echo "ok   - $1"; }
fail() {
	echo "FAIL - $1" >&2
	failures=$((failures + 1))
}

# expect_fail <label> <expected stderr fragment> <command...>
expect_fail() {
	local label="$1" fragment="$2" out
	shift 2
	if out="$("$@" 2>&1)"; then
		fail "$label (command succeeded, expected failure)"
	elif grep -qF -- "$fragment" <<<"$out"; then
		pass "$label"
	else
		fail "$label (failed, but without '$fragment'): $out"
	fi
}

# A throwaway copy of the plugin to break on purpose.
fresh_copy() {
	local dir="$WORK/$1"
	mkdir -p "$dir"
	rsync -a --exclude /vendor --exclude /.phpunit.result.cache "$PLUGIN/" "$dir/"
	echo "$dir"
}

export SOURCE_DATE_EPOCH=1767225600

# ---------------------------------------------------------------------------
# 1. The real tree packages, and the zip holds exactly the runtime files.
# ---------------------------------------------------------------------------
if ! bash "$PKG" "$WORK/a" >"$WORK/a.log" 2>&1; then
	fail "the plugin packages cleanly"
	cat "$WORK/a.log" >&2
else
	pass "the plugin packages cleanly"
	VERSION="$(sed -nE 's/^[ *]*Version:[[:space:]]*([^[:space:]]+).*/\1/p' "$PLUGIN/blueline-core.php" | head -n1)"
	ZIP="$WORK/a/blueline-core-$VERSION.zip"

	# Independent oracle: the runtime files, derived from the tree, not from .distignore.
	expected="$(cd "$PLUGIN" && {
		printf '%s\n' blueline-core.php uninstall.php README.md
		find includes templates -type f -name '*.php'
	} | sed 's#^#blueline-core/#' | LC_ALL=C sort)"
	actual="$(unzip -Z1 "$ZIP" | grep -v '/$' | LC_ALL=C sort)"
	if [ "$expected" = "$actual" ]; then
		pass "the zip contains exactly the runtime files ($(wc -l <<<"$actual") files)"
	else
		fail "the zip's file list differs from the runtime files"
		diff <(echo "$expected") <(echo "$actual") >&2
	fi

	if unzip -Z1 "$ZIP" | grep -qE '^blueline-core/(tests/|vendor|composer\.|phpunit\.xml|phpcs\.xml|\.distignore|\.gitignore|\.phpunit)'; then
		fail "no dev files in the zip"
	else
		pass "no dev files in the zip"
	fi

	if unzip -Z1 "$ZIP" | grep -qv '^blueline-core/'; then
		fail "everything is under blueline-core/"
	else
		pass "everything is under blueline-core/"
	fi

	if [ -f "$ZIP.sha256" ] && (cd "$WORK/a" && sha256sum -c "blueline-core-$VERSION.zip.sha256" >/dev/null 2>&1); then
		pass "the .sha256 file verifies"
	else
		fail "the .sha256 file verifies"
	fi

	# Reproducible: a second build, from a different path and clock, is byte-identical.
	sleep 1
	if bash "$PKG" "$WORK/b" >/dev/null 2>&1 && cmp -s "$ZIP" "$WORK/b/blueline-core-$VERSION.zip"; then
		pass "two builds are byte-identical"
	else
		fail "two builds are byte-identical"
	fi
fi

# ---------------------------------------------------------------------------
# 2. The guard refuses disagreeing version sources.
# ---------------------------------------------------------------------------
if php "$GUARD" >/dev/null 2>&1; then
	pass "the guard accepts the real tree"
else
	fail "the guard accepts the real tree"
fi

expect_fail "the guard refuses an expected version that is not the header's" "was expected" php "$GUARD" 9.9.9

c="$(fresh_copy const)"
sed -i "s/define( 'BLUELINE_CORE_VERSION', '[^']*'/define( 'BLUELINE_CORE_VERSION', '9.9.9'/" "$c/blueline-core.php"
expect_fail "the guard refuses a drifted BLUELINE_CORE_VERSION" "BLUELINE_CORE_VERSION" php "$GUARD" "" "$c"
expect_fail "the packager refuses a drifted BLUELINE_CORE_VERSION" "BLUELINE_CORE_VERSION" env BLUELINE_PLUGIN_DIR="$c" bash "$PKG" "$WORK/o-const"

c="$(fresh_copy composer-version)"
sed -i 's/"license": "GPL-2.0-or-later",/"license": "GPL-2.0-or-later", "version": "9.9.9",/' "$c/composer.json"
expect_fail "the guard refuses a drifted composer.json version" "composer.json version" php "$GUARD" "" "$c"

c="$(fresh_copy composer-php)"
sed -i 's/"php": ">=[0-9.]*"/"php": ">=7.4"/' "$c/composer.json"
expect_fail "the guard refuses a composer require.php that disagrees with Requires PHP" "require.php floor" php "$GUARD" "" "$c"

c="$(fresh_copy stable-tag)"
printf '\n**Stable tag:** 9.9.9\n' >>"$c/README.md"
expect_fail "the guard refuses a drifted README Stable tag" "Stable tag" php "$GUARD" "" "$c"

c="$(fresh_copy bad-version)"
sed -i 's/^\( \* Version: *\).*/\1banana/' "$c/blueline-core.php"
expect_fail "the guard refuses a non-semver Version header" "not MAJOR.MINOR.PATCH" php "$GUARD" "" "$c"

# ---------------------------------------------------------------------------
# 3. The packager refuses a bad tree.
# ---------------------------------------------------------------------------
c="$(fresh_copy syntax)"
printf '<?php\nfunction broken( {\n' >"$c/includes/search/search.php"
expect_fail "the packager refuses a PHP syntax error" "syntax error" env BLUELINE_PLUGIN_DIR="$c" bash "$PKG" "$WORK/o-syntax"

c="$(fresh_copy missing-module)"
rm "$c/includes/privacy/privacy.php"
expect_fail "the packager refuses a missing module file" "privacy/privacy.php" env BLUELINE_PLUGIN_DIR="$c" bash "$PKG" "$WORK/o-module"

c="$(fresh_copy stray)"
printf '{}\n' >"$c/includes/seo-meta/data.json"
expect_fail "the packager refuses an unexpected file type" "unexpected files" env BLUELINE_PLUGIN_DIR="$c" bash "$PKG" "$WORK/o-stray"

# Anchored .distignore: a nested tests/ directory is plugin code, not the dev suite.
c="$(fresh_copy nested-tests)"
mkdir -p "$c/includes/seo-meta/tests"
printf '<?php\ndefined( "ABSPATH" ) || exit;\n' >"$c/includes/seo-meta/tests/fixture.php"
if env BLUELINE_PLUGIN_DIR="$c" bash "$PKG" "$WORK/o-nested" >/dev/null 2>&1 \
	&& unzip -Z1 "$WORK"/o-nested/blueline-core-*.zip | grep -qxF 'blueline-core/includes/seo-meta/tests/fixture.php'; then
	pass ".distignore is anchored: includes/**/tests ships"
else
	fail ".distignore is anchored: includes/**/tests ships"
fi

# ---------------------------------------------------------------------------
# 4. Runtime composer dependencies ship in vendor/ (installed --no-dev); dev ones never.
# ---------------------------------------------------------------------------
if command -v composer >/dev/null 2>&1; then
	c="$(fresh_copy runtime-dep)"
	mkdir -p "$WORK/dep-pkg/src"
	cat >"$WORK/dep-pkg/composer.json" <<'JSON'
{ "name": "acme/runtime-dep", "version": "1.0.0", "autoload": { "classmap": ["src/"] } }
JSON
	printf '<?php\nclass Acme_Runtime_Dep {}\n' >"$WORK/dep-pkg/src/Dep.php"
	rm -f "$c/composer.lock"
	cat >"$c/composer.json" <<JSON
{
  "name": "arl/blueline-core", "type": "wordpress-plugin", "license": "GPL-2.0-or-later",
  "repositories": [ { "type": "path", "url": "$WORK/dep-pkg", "options": { "symlink": false } } ],
  "require": { "php": ">=8.3", "acme/runtime-dep": "*" },
  "require-dev": { "phpunit/phpunit": "^12.0" }
}
JSON
	if env BLUELINE_PLUGIN_DIR="$c" bash "$PKG" "$WORK/o-dep" >"$WORK/dep.log" 2>&1; then
		list="$(unzip -Z1 "$WORK"/o-dep/blueline-core-*.zip)"
		if grep -qxF 'blueline-core/vendor/autoload.php' <<<"$list" \
			&& grep -qxF 'blueline-core/vendor/acme/runtime-dep/src/Dep.php' <<<"$list" \
			&& ! grep -qE '^blueline-core/(vendor/(phpunit|bin)|composer\.(json|lock)$)' <<<"$list"; then
			pass "runtime dependencies ship in vendor/, dev ones and composer files do not"
		else
			fail "runtime dependencies ship in vendor/, dev ones and composer files do not"
			echo "$list" >&2
		fi
	else
		fail "the packager handles a plugin with runtime dependencies"
		cat "$WORK/dep.log" >&2
	fi
else
	echo "skip - runtime-dependency case (composer is not installed)"
fi

echo
if [ "$failures" -eq 0 ]; then
	echo "plugin release self-test: all checks passed"
	exit 0
fi
echo "plugin release self-test: $failures check(s) failed" >&2
exit 1
