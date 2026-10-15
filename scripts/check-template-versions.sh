#!/usr/bin/env bash
# Report theme template overrides whose plugin original has moved on.
#
# Every override under themes/blueline/woocommerce/ and themes/blueline/sportspress/
# names its source in its header:
#
#   * Copied from WooCommerce 11.0.1: woocommerce/templates/emails/customer-note.php
#   * @version 10.4.0
#
# or, for SportsPress, on the @version line itself:
#
#   * @version 2.7.23 (copied from SportsPress Pro 2.7.29: sportspress-pro/includes/sportspress/templates/event-list.php)
#
# The path starts with the plugin's directory. A woocommerce/ override without that
# line is compared with WooCommerce's own templates/ copy of the same path, which is
# where WooCommerce itself looks. This script reads each original's
# @version from staging's plugin directory (read-only: grep over `docker exec`,
# no WP-CLI, no database) and compares it with the override's. When the original
# has no @version at all, it compares the plugin's installed Version with the one
# the header recorded instead. Theme-only templates say "Upstream: none" and are
# listed but never fail.
#
# Usage:
#   ./scripts/check-template-versions.sh                     # staging over SSH (scripts/lib/hosts.env)
#   BLUELINE_PLUGINS_DIR=/path/to/wp-content/plugins ./scripts/check-template-versions.sh
#
# Exit 0 = every override matches its original; 1 = at least one needs a re-copy
# (DRIFT), a look (REVIEW), has no recorded source (NO-SOURCE) or lost its original
# (MISSING); 2 = could not read the originals.
#
# Manual by design (not CI): CI has no access to staging's plugins. Run it after
# updating WooCommerce, its extensions or SportsPress on staging.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
THEME="${BLUELINE_THEME_DIR:-$(cd "$SCRIPT_DIR/.." && pwd)/themes/blueline}"
CONTAINER="staging-wp"
REMOTE_PLUGINS="/var/www/html/wp-content/plugins"

# The first "@version" line, read the way WooCommerce's template status screen
# reads it (WC_Admin_Status::get_file_version()), reduced to its first word.
version_of() {
	awk '/^[ \t\/*#@]*@version/ { sub(/^[ \t\/*#@]*@version[ \t]*/, ""); split($0, w, /[ \t]+/); print w[1]; exit }'
}

# A POSIX sh script, run where the plugins are (inside the staging container, or
# locally) with the plugins directory as $1. The request list follows it as a
# here-document, so script and data travel together on stdin.
# shellcheck disable=SC2016 # the probe is a script for the other shell; nothing expands here
PROBE='
base="$1"
while IFS= read -r item; do
	case "$item" in
	plugin:*)
		dir="${item#plugin:}"
		main=""
		for f in "$base/$dir"/*.php; do
			if [ -f "$f" ] && head -c 8192 "$f" | grep -q "Plugin Name:"; then main="$f"; break; fi
		done
		ver=""
		[ -n "$main" ] && ver="$(head -c 8192 "$main" | sed -n "s/^[ \t\/*#@]*Version:[ \t]*\([^ \t]*\).*/\1/p" | head -n 1)"
		printf "plugin:%s\t%s\n" "$dir" "$ver"
		;;
	*)
		if [ -f "$base/$item" ]; then
			printf "%s\tFOUND\t" "$item"
			head -c 8192 "$base/$item" | tr "\r" "\n" | awk "/^[ \t\/*#@]*@version/ { sub(/^[ \t\/*#@]*@version[ \t]*/, \"\"); split(\$0, w, /[ \t]+/); print w[1]; found=1; exit } END { if (!found) print \"\" }"
		else
			printf "%s\tMISSING\t\n" "$item"
		fi
		;;
	esac
done <<"BLUELINE_REQUESTS"
'

# One row per override: file, recorded plugin, recorded plugin version, original path, override @version.
ROWS="$(mktemp)"
trap 'rm -f "$ROWS" "$ROWS.req" "$ROWS.res"' EXIT
while IFS= read -r -d '' file; do
	rel="${file#"$THEME"/}"
	head="$(head -n 120 "$file" | tr -d '\r')"
	if grep -q 'Upstream: none' <<<"$head"; then
		printf '%s\t-\t-\t-\t-\n' "$rel" >>"$ROWS"
		continue
	fi
	src="$(grep -oiE 'copied from .+ [0-9][0-9.]*: [^ )]+\.php' <<<"$head" | head -n 1 || true)"
	over="$(version_of <<<"$head")"
	if [ -n "$src" ]; then
		src="${src#[Cc]opied from }"
		path="${src##*: }"
		label="${src%: *}"
		pver="${label##* }"
		pname="${label% *}"
	elif [[ "$rel" == woocommerce/* ]]; then
		# No "Copied from" line: WooCommerce's own convention says theme
		# woocommerce/<x>.php overrides woocommerce/templates/<x>.php.
		path="woocommerce/templates/${rel#woocommerce/}"
		pname="WooCommerce"
		pver="?"
	else
		printf '%s\t?\t?\t?\t?\n' "$rel" >>"$ROWS"
		continue
	fi
	printf '%s\t%s\t%s\t%s\t%s\n' "$rel" "$pname" "$pver" "$path" "${over:--}" >>"$ROWS"
done < <(find "$THEME/woocommerce" "$THEME/sportspress" -name '*.php' ! -path "$THEME/sportspress/index.php" -print0 | LC_ALL=C sort -z)

# Ask for each original and each plugin's installed version in one round trip.
{
	printf '%s\n' "$PROBE"
	awk -F'\t' '$4 != "-" && $4 != "?" { print $4; split($4, p, "/"); print "plugin:" p[1] }' "$ROWS" | LC_ALL=C sort -u
	echo 'BLUELINE_REQUESTS'
} >"$ROWS.req"

if [ -n "${BLUELINE_PLUGINS_DIR:-}" ]; then
	sh -s -- "$BLUELINE_PLUGINS_DIR" <"$ROWS.req" >"$ROWS.res"
else
	# shellcheck disable=SC1091
	[ -f "$SCRIPT_DIR/lib/hosts.env" ] && source "$SCRIPT_DIR/lib/hosts.env"
	HOST="${BLUELINE_STAGING_HOST:?set BLUELINE_STAGING_HOST (see scripts/lib/hosts.env.example) or BLUELINE_PLUGINS_DIR}"
	PORT="${BLUELINE_STAGING_PORT:-22}"
	if ! ssh -p "$PORT" -o ConnectTimeout=10 "$HOST" "docker exec -i $CONTAINER sh -s -- $REMOTE_PLUGINS" <"$ROWS.req" >"$ROWS.res"; then
		echo "check-template-versions: could not read staging's plugins ($HOST via $CONTAINER)" >&2
		exit 2
	fi
fi
[ -s "$ROWS.res" ] || { echo "check-template-versions: no answer for the originals" >&2; exit 2; }

awk -F'\t' -v RES="$ROWS.res" '
BEGIN {
	while ((getline line < RES) > 0) {
		n = split(line, f, "\t")
		if (f[1] ~ /^plugin:/) { plugin[substr(f[1], 8)] = f[2] } else { state[f[1]] = f[2]; orig[f[1]] = f[3] }
	}
	fmt = "%-62s %-10s %-10s %s\n"
	printf fmt, "OVERRIDE", "OURS", "ORIGINAL", "STATUS"
	bad = 0
}
{
	file = $1; pname = $2; pver = $3; path = $4; over = $5
	if (path == "-") { printf fmt, file, "-", "-", "ok (theme-authored, no original)"; next }
	if (path == "?") { printf fmt, file, "-", "-", "NO-SOURCE: header names no \"Copied from\" original"; bad++; next }
	split(path, p, "/"); installed = plugin[p[1]]
	if (state[path] != "FOUND") { printf fmt, file, over, "-", "MISSING: " path " not in " pname " " installed; bad++; next }
	if (orig[path] != "") {
		if (over == "-") { printf fmt, file, "-", orig[path], "NO-VERSION: record the original'"'"'s @version (and a \"Copied from\" line)"; bad++ }
		else if (over == orig[path]) { printf fmt, file, over, orig[path], "ok" }
		else { printf fmt, file, over, orig[path], "DRIFT: re-copy " path " (" pname " " installed ") and re-apply the edits"; bad++ }
	} else if (installed == pver) {
		printf fmt, file, "-", "-", "ok (original unversioned; " pname " still " pver ")"
	} else {
		printf fmt, file, "-", "-", "REVIEW: original unversioned and " pname " moved " pver " -> " installed "; diff " path; bad++
	}
}
END {
	print ""
	if (bad) { printf "%d override(s) need attention.\n", bad; exit 1 }
	print "All overrides match their originals."
}' "$ROWS"
