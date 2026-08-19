#!/usr/bin/env bash
#
# Compare the Code Snippets in this repo against the live site, and optionally refresh them.
#
# The DATABASE is authoritative. Code Snippets has no git integration: its flat-file feature
# (wp-content/code-snippets/) is a cache generated FROM the database, and its cloud sync goes
# to Code Snippets' own service. This repo is therefore a mirror, and the only real risk is
# silent drift. This makes drift loud.
#
#   ./scripts/sync-snippets.sh                   # check only; exits 1 if the repo differs from live
#   ./scripts/sync-snippets.sh --write           # overwrite the repo copies with what is live
#   ./scripts/sync-snippets.sh --list-untracked  # also list live snippets this repo does not track
#
# Which snippets: every snippets/<id>-<name>.php file in the repo, matched by leading ID.
# Override the host with SNIPPET_SYNC_HOST=... (default: production-host, from ~/.ssh/config).

set -euo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SNIP_DIR="$REPO_DIR/snippets"
MANIFEST="$SNIP_DIR/manifest.tsv"
SSH_HOST="${SNIPPET_SYNC_HOST:-production-host}"
WP_PATH="/var/www/rookiehockey.ca/htdocs"

MODE="check"
LIST_UNTRACKED=0
case "${1:-}" in
	--write)           MODE="write" ;;
	--list-untracked)  LIST_UNTRACKED=1 ;;
	"")                : ;;
	*) echo "usage: $(basename "$0") [--write|--list-untracked]" >&2; exit 2 ;;
esac

[[ -d "$SNIP_DIR" ]] || { echo "no snippets/ directory at $SNIP_DIR" >&2; exit 2; }

mapfile -t IDS < <(find "$SNIP_DIR" -maxdepth 1 -name '*.php' -printf '%f\n' \
	| sed -n 's/^\([0-9]\+\)-.*\.php$/\1/p' | sort -n)

[[ ${#IDS[@]} -gt 0 ]] || { echo "no snippets/<id>-<name>.php files found" >&2; exit 2; }

# Fetch every snippet in one round trip and filter locally -- avoids interpolating an ID list
# through bash -> ssh -> wp-cli -> mysql, which is where an earlier version of this broke.
# PHP's base64_encode() does not wrap, unlike MySQL's TO_BASE64(), so the TSV stays intact.
REMOTE_PHP='global $wpdb; foreach($wpdb->get_results("SELECT id,name,scope,active,priority,code FROM wp_snippets ORDER BY id") as $r){ echo $r->id,"\t",base64_encode($r->code),"\t",str_replace(array("\t","\n","\r")," ",$r->name),"\t",$r->scope,"\t",$r->active,"\t",$r->priority,"\n"; }'

LIVE="$(ssh -o BatchMode=yes "$SSH_HOST" \
	"sudo -u www-data /usr/local/bin/wp --path=$WP_PATH eval '$REMOTE_PHP' --skip-themes" 2>/dev/null \
	| grep -E '^[0-9]+	')" || true

[[ -n "$LIVE" ]] || { echo "could not read snippets from $SSH_HOST" >&2; exit 1; }

TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
DRIFT=0
: > "$TMP/manifest.new"

for id in "${IDS[@]}"; do
	# No `exit` in awk: it would close the pipe early, and once $LIVE exceeds the 64K pipe buffer
	# printf takes SIGPIPE, which `set -o pipefail` turns into a 141 abort. Ids are unique, so
	# reading the whole stream costs nothing.
	row="$(printf '%s\n' "$LIVE" | awk -F'\t' -v want="$id" '$1==want {print}')"
	repo_file="$(find "$SNIP_DIR" -maxdepth 1 -name "${id}-*.php" -print -quit)"

	if [[ -z "$row" ]]; then
		printf 'GONE     %-44s snippet %s no longer exists on live\n' "$(basename "$repo_file")" "$id"
		DRIFT=1
		continue
	fi

	IFS=$'\t' read -r _ b64 name scope active priority <<< "$row"
	printf '%s\t%s\t%s\t%s\t%s\n' "$id" "$name" "$scope" "$active" "$priority" >> "$TMP/manifest.new"
	printf '%s' "$b64" | base64 -d > "$TMP/$id.live"

	if cmp -s "$repo_file" "$TMP/$id.live"; then
		printf 'ok       %-44s (%s, active=%s)\n' "$(basename "$repo_file")" "$scope" "$active"
		continue
	fi

	DRIFT=1
	printf 'DRIFT    %-44s repo=%s bytes  live=%s bytes\n' \
		"$(basename "$repo_file")" "$(stat -c%s "$repo_file")" "$(stat -c%s "$TMP/$id.live")"

	if [[ "$MODE" == "write" ]]; then
		cp "$TMP/$id.live" "$repo_file"
		echo "         updated from live"
	else
		diff -u --label "repo/$(basename "$repo_file")" --label "live/snippet-$id" \
			"$repo_file" "$TMP/$id.live" | sed 's/^/         /' | head -40 || true
	fi
done

# Snippets on live that this repo does not track. Not drift -- the repo deliberately covers
# only the snippets added for the W2026-27 work -- so summarise unless asked.
UNTRACKED=()
while IFS=$'\t' read -r lid _ lname lscope lactive _; do
	[[ -n "${lid:-}" ]] || continue
	if [[ ! " ${IDS[*]} " == *" $lid "* ]]; then
		UNTRACKED+=("$(printf '%-4s %-38s (%s, active=%s)' "$lid" "${lname:0:38}" "$lscope" "$lactive")")
	fi
done <<< "$LIVE"

if [[ ${#UNTRACKED[@]} -gt 0 ]]; then
	if [[ "$LIST_UNTRACKED" -eq 1 ]]; then
		echo
		echo "${#UNTRACKED[@]} live snippets not tracked in this repo:"
		printf '  %s\n' "${UNTRACKED[@]}"
	else
		echo "         (${#UNTRACKED[@]} other live snippets are not tracked here — --list-untracked to see them)"
	fi
fi

# Metadata drift counts too: a snippet deactivated on live is a real change even if code matches.
if [[ -f "$MANIFEST" ]] && ! diff -q <(tail -n +2 "$MANIFEST") "$TMP/manifest.new" >/dev/null 2>&1; then
	DRIFT=1
	echo "DRIFT    manifest.tsv (name/scope/active/priority changed)"
	diff -u --label repo/manifest.tsv --label live \
		<(tail -n +2 "$MANIFEST") "$TMP/manifest.new" | sed 's/^/         /' || true
fi

if [[ "$MODE" == "write" ]]; then
	{ printf '# id\tname\tscope\tactive\tpriority\n'; cat "$TMP/manifest.new"; } > "$MANIFEST"
	echo
	echo "repo refreshed from live — review with: git diff"
	exit 0
fi

echo
if [[ "$DRIFT" -eq 0 ]]; then
	echo "in sync with $SSH_HOST"
else
	echo "repo differs from live — refresh with: $(basename "$0") --write"
fi
exit "$DRIFT"
