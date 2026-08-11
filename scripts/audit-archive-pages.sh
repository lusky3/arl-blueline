#!/usr/bin/env bash
# Audit archived season pages (Standings / Rosters / Stats history) under blueline.
#
# These ~30 pages are years-old content -- mostly headings, tables and
# SportsPress shortcodes -- authored against the ThemeBoy `rookie` theme.
# This script enumerates every one of them, fetches it from staging, and
# asserts:
#   - HTTP 200 (a 301 is only accepted when it is a documented Page Links
#     To redirect -- see LINKS_TO below -- everything else that isn't a
#     plain 200 is a hard failure; this script never passes `-L` to curl,
#     so a redirect can never silently be read as a 200)
#   - no `rookie-` OR `rookie/` token anywhere in the response body (class,
#     asset path, or otherwise -- broader than just `class="..."` because a
#     hardcoded <img src="/wp-content/themes/rookie-child/..."> left over in
#     page content is exactly the kind of Rookie-specific dependency this
#     audit exists to catch, not only a CSS class). The `/` alternative is
#     load-bearing: `rookie-` alone REQUIRES the hyphen, so a hardcoded
#     `/wp-content/themes/rookie/style.css` -- the actual parent theme this
#     whole audit exists to detect -- did not match it.
#   - no PHP error/warning/notice/deprecated string leaked into the response,
#     whether PHP emitted it in plain text or HTML-wrapped (see has_php_error)
#
# Run `./scripts/audit-archive-pages.sh --self-test` to exercise the two
# content checks above against known fixture strings, offline, before ever
# touching the network -- no ssh/curl involved. Do this after editing either
# check; it is the regression test for the checks themselves.
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
#     --posts_per_page=-1 --format=csv --fields=ID,post_name,post_parent"
# and cross-checked against both live nav menus (`menu-3-0`, the current
# `primary` location, and the legacy `menu-2-0`):
#   - 2557 "Past Standings" (parent of every standings-* archive page)
#   - 2583 "Past Rosters"   (parent of every rosters-* archive page)
#   - 3429 "Past Stats"     (parent of every stats-* archive page; NOT
#                            linked from the current nav, but still published
#                            and publicly reachable -- audited anyway, since
#                            "no longer in the menu" isn't "no longer live")
#
# HUB_IDS and EXTRA_IDS below are a hardcoded snapshot from that manual
# investigation (2026-08-11), not auto-discovered. This is a one-off audit
# script, not a live-discovery crawler: a brand new archive hub added later
# (e.g. a "Past Playoffs" section) would need a human to notice it and add
# its id here. Re-run the enumeration commands above periodically -- ideally
# right before each production cutover -- rather than trusting this list to
# stay complete on its own.
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
#
# Page Links To: the three archive HUB pages themselves (2557/2583/3429)
# have `_links_to` postmeta set (confirmed 2026-08-11 -- they're the nav's
# non-clickable "#" dropdown triggers), but none of the 30 audited child
# pages did at that time. If a child page ever gains `_links_to` metadata
# later, its URL redirecting (301/302/307/308) is expected, not a bug --
# LINKS_TO below is fetched fresh on every run precisely so that case
# reads as an informational pass with a clear reason, not a confusing
# false failure that sends someone hunting for a regression that isn't one.

set -uo pipefail

SSH_HOST="root@staging-host.example"
SSH_PORT=SSH_PORT
BASE="${BASE:-https://staging.rookiehockey.ca}"
HUB_IDS=(2557 2583 3429) # past-standings, past-rosters, past-stats
EXTRA_IDS=(14114)        # root-level anomaly, see header comment above

# Plausibility floors against a truncated fetch (dropped ssh connection,
# WP-CLI warning corrupting the CSV mid-stream, etc.) silently shrinking the
# audit instead of failing it. These are floors only -- they cannot detect a
# fetch truncated at a per-page CAP, which is why fetch_pages() below asks for
# -1 rather than any finite number. Today's real numbers: the raw page table is
# 110 rows (109 pages + header); the derived archive-page target list is 30.
# Both floors are set well below today's real count so routine content
# growth/pruning never trips them, but a fetch that came back a fraction of
# its real size will.
MIN_PAGE_ROWS=100
MIN_TARGET_PAGES=20

# ---------------------------------------------------------------------------
# Content checks (pure functions -- no network). Exercised offline by
# --self-test below; keep these dependency-free so that mode never needs ssh.
# ---------------------------------------------------------------------------

# Detect a leaked PHP error/warning/notice/deprecated message. Tolerant of
# both plain-text output (html_errors=Off) and HTML-wrapped output
# (html_errors=On, the more common default wherever display_errors is also
# on). PHP's html_errors formatter wraps the error class name AND the
# trailing line number in <b>...</b> tags -- e.g.
#   <b>Notice</b>:  Undefined variable $foo in <b>...</b> on line <b>42</b>
# -- so a regex requiring digits immediately after literal "on line " (the
# first version of this check) never matches that form: a genuine leaked
# notice would register as "no PHP error", a vacuous pass. Strip HTML tags
# before matching so both forms collapse to the same shape, then require the
# full "ClassName: ... in FILE on line N" structure PHP always emits -- that
# structure, not just the word "Notice" or "Warning" on its own, is what
# keeps this from false-positiving on ordinary page prose (a "Cookie Notice"
# plugin's own injected copy showed up during manual testing on these very
# pages and does NOT match this pattern).
has_php_error() {
  local body="$1" detagged
  detagged="$(sed -E 's/<[^>]+>//g' <<<"$body")"
  grep -iEo '(Fatal error|Parse error|Warning|Notice|Deprecated): .*on line [0-9]+' <<<"$detagged"
}

# Detect any rookie- or rookie/ token in the raw body (class, id, hardcoded
# asset path, whatever) -- broader than just class="...", see header comment.
#
# The separator character is the whole point of this pattern. Requiring `-`
# (the original version) matched `rookie-child` but NOT the plain parent theme
# path `/wp-content/themes/rookie/style.css`, i.e. it was blind to the single
# most likely leftover it exists to find. Requiring one of `-` or `/` keeps the
# only negative fixture that matters -- the site's own domain name,
# `rookiehockey.ca`, whose next character is `h` -- correctly excluded.
has_rookie_token() {
  local body="$1"
  grep -oiE 'rookie[-/][A-Za-z0-9_./-]*' <<<"$body"
}

self_test() {
  local failures=0 got

  got="$(has_php_error 'Notice: Undefined variable: foo in /var/www/html/wp-content/themes/blueline/single.php on line 42')"
  if [ -z "$got" ]; then
    echo "SELF-TEST FAIL: plain-mode (html_errors=Off) PHP notice not detected" >&2
    failures=$((failures + 1))
  else
    echo "SELF-TEST ok: plain-mode PHP notice detected -> $got"
  fi

  got="$(has_php_error $'<br />\n<b>Notice</b>:  Undefined variable $foo in <b>/var/www/html/wp-content/themes/blueline/single.php</b> on line <b>42</b><br />\n')"
  if [ -z "$got" ]; then
    echo "SELF-TEST FAIL: html_errors=On PHP notice not detected -- this is the exact blind spot flagged in review" >&2
    failures=$((failures + 1))
  else
    echo "SELF-TEST ok: html_errors=On PHP notice detected -> $got"
  fi

  got="$(has_php_error '<p>Cookie Notice: We use cookies to improve your experience on this site.</p>')"
  if [ -n "$got" ]; then
    echo "SELF-TEST FAIL: false positive on benign 'Cookie Notice:' prose -> $got" >&2
    failures=$((failures + 1))
  else
    echo "SELF-TEST ok: benign 'Cookie Notice:' prose not flagged"
  fi

  got="$(has_rookie_token '<div class="bl-standings-table">See rookiehockey.ca for details</div>')"
  if [ -n "$got" ]; then
    echo "SELF-TEST FAIL: false positive on domain name 'rookiehockey' -> $got" >&2
    failures=$((failures + 1))
  else
    echo "SELF-TEST ok: domain name 'rookiehockey' not flagged as a rookie- token"
  fi

  got="$(has_rookie_token '<div class="rookie-standings-table">old markup</div>')"
  if [ -z "$got" ]; then
    echo "SELF-TEST FAIL: genuine rookie- class not detected" >&2
    failures=$((failures + 1))
  else
    echo "SELF-TEST ok: genuine rookie- class detected -> $got"
  fi

  # The exact blind spot flagged in the whole-branch review: the parent theme's
  # own asset path has NO hyphen after "rookie", so the original
  # 'rookie-[A-Za-z0-9_-]*' pattern never matched it.
  got="$(has_rookie_token '<link rel="stylesheet" href="/wp-content/themes/rookie/style.css">')"
  if [ -z "$got" ]; then
    echo "SELF-TEST FAIL: hyphen-less parent theme path /themes/rookie/ not detected" >&2
    failures=$((failures + 1))
  else
    echo "SELF-TEST ok: hyphen-less parent theme path detected -> $got"
  fi

  got="$(has_rookie_token 'Visit https://rookiehockey.ca/standings for the full table')"
  if [ -n "$got" ]; then
    echo "SELF-TEST FAIL: false positive on a rookiehockey.ca URL -> $got" >&2
    failures=$((failures + 1))
  else
    echo "SELF-TEST ok: rookiehockey.ca URL not flagged as a rookie- token"
  fi

  if [ "$failures" -eq 0 ]; then
    echo "SELF-TEST: all fixtures behaved as expected"
    return 0
  fi
  echo "SELF-TEST: $failures fixture(s) failed" >&2
  return 1
}

if [ "${1:-}" = "--self-test" ]; then
  self_test
  exit $?
fi

# ---------------------------------------------------------------------------
# Fetch phase: page list + Page Links To membership. Fails loudly (non-zero,
# clear message) rather than silently auditing a shrunk list.
# ---------------------------------------------------------------------------

# `--posts_per_page=-1`, never a finite cap. The earlier `=200` was an upper
# BOUND with no check that the result had not hit it: the site has 109 pages
# today, so at 201 the fetch would truncate silently, the 200-row result would
# still clear MIN_PAGE_ROWS below, and every page past the cap would simply be
# skipped from the audit with no warning. An unbounded fetch cannot truncate at
# a cap it does not have.
fetch_pages() {
  ssh -p "$SSH_PORT" "$SSH_HOST" \
    "swp post list --post_type=page --posts_per_page=-1 --format=csv --fields=ID,post_name,post_parent,post_status"
}

fetch_links_to_ids() {
  ssh -p "$SSH_PORT" "$SSH_HOST" \
    "swp post list --post_type=page --posts_per_page=-1 --meta_key=_links_to --format=csv --field=ID"
}

STDERR_TMP="$(mktemp)"
trap 'rm -f "$STDERR_TMP"' EXIT

PAGE_CSV="$(fetch_pages 2>"$STDERR_TMP")"
FETCH_STATUS=$?
if [ "$FETCH_STATUS" -ne 0 ]; then
  echo "FATAL: ssh/swp exited $FETCH_STATUS fetching the page list from $SSH_HOST" >&2
  echo "--- remote stderr ---" >&2
  cat "$STDERR_TMP" >&2
  exit 2
fi

PAGE_ROW_COUNT="$(grep -c . <<<"$PAGE_CSV")"
if [ "$PAGE_ROW_COUNT" -lt "$MIN_PAGE_ROWS" ]; then
  echo "FATAL: fetched page table has only $PAGE_ROW_COUNT row(s) (want >= $MIN_PAGE_ROWS)." >&2
  echo "       This looks like a truncated transfer (dropped ssh connection, WP-CLI" >&2
  echo "       warning breaking the CSV mid-stream, etc.) -- refusing to silently" >&2
  echo "       audit a smaller page list than the real site has." >&2
  exit 2
fi

declare -A SLUG=() PARENT=() STATUS=()
PARSE_ERRORS=0
while IFS=, read -r id name parent status; do
  [ -z "$id" ] && continue
  [ "$id" = "ID" ] && continue
  if ! [[ "$id" =~ ^[0-9]+$ ]]; then
    echo "WARN: unparseable page-list row (non-numeric id): '$id,$name,$parent,$status'" >&2
    PARSE_ERRORS=$((PARSE_ERRORS + 1))
    continue
  fi
  SLUG["$id"]="$name"
  PARENT["$id"]="$parent"
  STATUS["$id"]="$status"
done <<<"$PAGE_CSV"

if [ "$PARSE_ERRORS" -gt 0 ]; then
  echo "FATAL: $PARSE_ERRORS page-list row(s) failed to parse as 'ID,name,parent,status'." >&2
  echo "       A stray WP-CLI warning printed to stdout likely corrupted the CSV --" >&2
  echo "       refusing to audit a possibly-incomplete list." >&2
  exit 2
fi

if [ "${#SLUG[@]}" -eq 0 ]; then
  echo "FATAL: parsed zero pages from $SSH_HOST (ssh/swp failed silently?)" >&2
  exit 2
fi

declare -A LINKS_TO=()
LINKS_TO_CSV="$(fetch_links_to_ids 2>/dev/null || true)"
while IFS= read -r id; do
  [ -z "$id" ] && continue
  [ "$id" = "ID" ] && continue
  LINKS_TO["$id"]=1
done <<<"$LINKS_TO_CSV"

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

if [ "${#TARGET_IDS[@]}" -lt "$MIN_TARGET_PAGES" ]; then
  echo "FATAL: derived target list has only ${#TARGET_IDS[@]} page(s) (want >= $MIN_TARGET_PAGES)." >&2
  echo "       The raw page table passed its own floor, but the archive-page list" >&2
  echo "       derived from it did not -- refusing to audit a shrunk target list." >&2
  exit 2
fi

# ---------------------------------------------------------------------------
# Per-page checks. Aggregates every result before reporting; never exits
# early on an individual failure.
# ---------------------------------------------------------------------------

FAIL=0
declare -a ROWS=()

check_page() {
  local id="$1" path url response body code rookie_hit php_hit

  path="$(resolve_path "$id")" || {
    ROWS+=("FAIL|$id|-|-|path-resolve-error")
    FAIL=1
    return
  }
  url="${BASE}${path}"

  # curl's EXIT STATUS is checked, not just "did anything come back". `-w
  # %{http_code}` is written even when the transfer aborts partway (exit 28
  # timeout, exit 18 partial file), so an empty-body test alone would happily
  # read code=200 off a response whose body was cut short after the headers --
  # and then pass both content assertions below against bytes that were never
  # received. scripts/smoke-staging.sh has always checked this correctly; this
  # is the same check.
  if ! response="$(curl -sS --max-time 30 -w '\n%{http_code}' "$url" 2>/dev/null)"; then
    ROWS+=("FAIL|$id|$path|-|curl-error (non-zero curl exit -- transfer failed or was truncated)")
    FAIL=1
    return
  fi
  if [ -z "$response" ]; then
    ROWS+=("FAIL|$id|$path|-|curl-error (empty response)")
    FAIL=1
    return
  fi
  code="${response##*$'\n'}"
  body="${response%$'\n'*}"

  if [ "$code" != "200" ]; then
    case "$code" in
      301 | 302 | 307 | 308)
        if [ -n "${LINKS_TO[$id]:-}" ]; then
          ROWS+=("PASS|$id|$path|$code|links-to-redirect (Page Links To meta present -- expected)")
          return
        fi
        ;;
    esac
    ROWS+=("FAIL|$id|$path|HTTP $code|-")
    FAIL=1
    return
  fi

  rookie_hit="$(has_rookie_token "$body" | sort -u | paste -sd, -)"
  php_hit="$(has_php_error "$body" | sort -u | paste -sd, -)"

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
