#!/usr/bin/env bash
# Audit archived season pages (Standings / Rosters / Stats history) under blueline.
#
# These ~30 pages are years-old content -- mostly headings, tables and
# SportsPress shortcodes -- authored against the ThemeBoy `rookie` theme.
# This script enumerates every one of them, fetches it from staging, and
# asserts:
#   - HTTP 200
#   - no `rookie-`-prefixed token anywhere in the response body (class,
#     asset path, or otherwise -- broader than just `class="..."` because a
#     hardcoded <img src="/wp-content/themes/rookie-child/..."> left over in
#     page content is exactly the kind of Rookie-specific dependency this
#     audit exists to catch, not only a CSS class)
#   - no PHP error/warning/notice/deprecated string leaked into the response
#
# Enumeration strategy (Task 15 brief, "How to enumerate them"):
#   `get_permalink()` is unreliable on this site -- the Page Links To plugin
#   filters it and can return an unrelated redirect target. So pages are
#   never asked for their own permalink; instead each page's front-end path
#   is built by walking `post_parent` up to the root and joining `post_name`
#   slugs, exactly as WordPress's own rewrite rules do for a hierarchical
#   page.
#
# Archive pages live under three hubs, discovered via
#   ssh -p SSH_PORT root@staging-host.example "swp post list --post_type=page \
#     --posts_per_page=200 --format=csv --fields=ID,post_name,post_parent"
# and cross-checked against both live nav menus (`menu-3-0`, the current
# `primary` location, and the legacy `menu-2-0`):
#   - 2557 "Past Standings" (parent of every standings-* archive page)
#   - 2583 "Past Rosters"   (parent of every rosters-* archive page)
#   - 3429 "Past Stats"     (parent of every stats-* archive page; NOT
#                            linked from the current nav, but still published
#                            and publicly reachable -- audited anyway, since
#                            "no longer in the menu" isn't "no longer live")
#
# Known anomaly, included explicitly below: page 14114 ("Rosters | S2018")
# appears in the nav under Past Rosters, but its own post_parent is 0, not
# 2583 -- it lives at site root (/rosters-s2018/) rather than nested under
# /rosters/past-rosters/. Confirmed via `swp post list` + `swp menu item
# list menu-3-0`; parent-walking alone would silently miss it, so it is
# added as a one-off.
#
# Known exclusion, NOT audited: page 13440 ("Rosters | W2018-2019" -- note
# the "2018-2019" slug, distinct from the live "rosters-w2018-19" id 54302)
# is `post_status=private` and is not linked from any menu. Anonymous
# requests to a private page's URL 404 by WordPress's own visibility rules,
# independent of theme -- pre-existing, not a blueline regression, out of
# scope for this task.
#
# Also out of scope per the brief: `/registration/*` child pages 404 by
# design (WooCommerce's `product_base` is `/registration`, shadowing every
# child of page 168). None of the archive hubs above are children of 168,
# so that trap does not apply to any URL this script builds -- noted here
# only so a future reader doesn't go looking for it.

set -uo pipefail

SSH_HOST="root@staging-host.example"
SSH_PORT=SSH_PORT
BASE="${BASE:-https://staging.rookiehockey.ca}"
HUB_IDS=(2557 2583 3429) # past-standings, past-rosters, past-stats
EXTRA_IDS=(14114)        # root-level anomaly, see header comment above

FAIL=0
declare -a ROWS=()

fetch_pages() {
  ssh -p "$SSH_PORT" "$SSH_HOST" \
    "swp post list --post_type=page --posts_per_page=200 --format=csv --fields=ID,post_name,post_parent,post_status" \
    2>/dev/null
}

declare -A SLUG=() PARENT=() STATUS=()
while IFS=, read -r id name parent status; do
  [ "$id" = "ID" ] && continue
  SLUG["$id"]="$name"
  PARENT["$id"]="$parent"
  STATUS["$id"]="$status"
done < <(fetch_pages)

if [ "${#SLUG[@]}" -eq 0 ]; then
  echo "FATAL: could not fetch page list from $SSH_HOST (ssh/swp failed)" >&2
  exit 2
fi

# Walk post_parent to the root, joining post_name slugs -- never get_permalink().
resolve_path() {
  local id="$1" parts="" cur="$id" guard=0
  while [ -n "$cur" ] && [ "$cur" != "0" ]; do
    if [ -z "${SLUG[$cur]+x}" ]; then
      echo "ERROR: unknown parent id $cur while resolving $id" >&2
      return 1
    fi
    parts="/${SLUG[$cur]}${parts}"
    cur="${PARENT[$cur]:-0}"
    guard=$((guard + 1))
    if [ "$guard" -gt 10 ]; then
      echo "ERROR: post_parent loop resolving id $id" >&2
      return 1
    fi
  done
  # No trailing slash: this site's permalink structure is non-trailing-slash
  # (confirmed live -- WordPress 301-redirects /path/ -> /path via its own
  # canonical-redirect logic), so a trailing slash here would make every
  # single page fail on a spurious, unrelated 301 rather than the real check.
  echo "${parts}"
}

# Build the target id list: every published, slugged page whose parent is
# one of the three archive hubs, plus the documented anomaly.
TARGET_IDS=()
for id in "${!PARENT[@]}"; do
  parent="${PARENT[$id]}"
  for hub in "${HUB_IDS[@]}"; do
    if [ "$parent" = "$hub" ] && [ "${STATUS[$id]}" = "publish" ] && [ -n "${SLUG[$id]}" ]; then
      TARGET_IDS+=("$id")
    fi
  done
done
for id in "${EXTRA_IDS[@]}"; do
  if [ "${STATUS[$id]:-}" = "publish" ] && [ -n "${SLUG[$id]:-}" ]; then
    TARGET_IDS+=("$id")
  fi
done

# Stable, numeric sort so re-runs diff cleanly.
mapfile -t TARGET_IDS < <(printf '%s\n' "${TARGET_IDS[@]}" | sort -n -u)

check_page() {
  local id="$1" path url response body code rookie_hit php_hit

  path="$(resolve_path "$id")" || {
    ROWS+=("FAIL|$id|-|path-resolve-error|-")
    FAIL=1
    return
  }
  url="${BASE}${path}"

  response="$(curl -sS --max-time 30 -w '\n%{http_code}' "$url" 2>/dev/null)"
  if [ -z "$response" ]; then
    ROWS+=("FAIL|$id|$path|curl-error|-")
    FAIL=1
    return
  fi
  code="${response##*$'\n'}"
  body="${response%$'\n'*}"

  if [ "$code" != "200" ]; then
    ROWS+=("FAIL|$id|$path|HTTP $code|-")
    FAIL=1
    return
  fi

  rookie_hit="$(grep -oiE 'rookie-[A-Za-z0-9_-]*' <<<"$body" | sort -u | paste -sd, -)"
  php_hit="$(grep -iEo '(Fatal error|Parse error|Warning|Notice|Deprecated): .*on line [0-9]+' <<<"$body" | sort -u | paste -sd, -)"

  if [ -n "$rookie_hit" ] || [ -n "$php_hit" ]; then
    local detail=""
    [ -n "$rookie_hit" ] && detail+="rookie-tokens:[$rookie_hit] "
    [ -n "$php_hit" ] && detail+="php-errors:[$php_hit]"
    ROWS+=("FAIL|$id|$path|200|$detail")
    FAIL=1
  else
    ROWS+=("PASS|$id|$path|200|-")
  fi
}

for id in "${TARGET_IDS[@]}"; do
  check_page "$id"
done

printf '%-5s  %-7s  %-58s  %-5s  %s\n' "RES" "ID" "PATH" "HTTP" "DETAIL"
PASS_COUNT=0
for row in "${ROWS[@]}"; do
  IFS='|' read -r res id path http detail <<<"$row"
  printf '%-5s  %-7s  %-58s  %-5s  %s\n' "$res" "$id" "$path" "$http" "$detail"
  [ "$res" = "PASS" ] && PASS_COUNT=$((PASS_COUNT + 1))
done

echo
echo "$PASS_COUNT/${#ROWS[@]} passed"
echo "known exclusion (not counted above): id=13440 rosters-w2018-2019 -- private, unlinked, expected 404 for anonymous requests"

exit $FAIL
