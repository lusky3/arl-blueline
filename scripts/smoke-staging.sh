#!/usr/bin/env bash
# HTTP smoke tests against staging. Usage: ./scripts/smoke-staging.sh
set -uo pipefail
BASE="${BASE:-https://staging.rookiehockey.ca}"
FAIL=0

check() { # check <path> <expected-http-code(s), "|"-separated> <grep-marker-or-->
  local path="$1" want="$2" marker="${3:--}" body code ok=0 w
  body="$(curl -sS --max-time 30 -w '\n%{http_code}' "$BASE$path" 2>/dev/null)" || { echo "FAIL $path (curl error)"; FAIL=1; return; }
  code="${body##*$'\n'}"; body="${body%$'\n'*}"
  for w in ${want//|/ }; do
    [ "$code" = "$w" ] && { ok=1; break; }
  done
  if [ "$ok" -ne 1 ]; then echo "FAIL $path -> HTTP $code (want $want)"; FAIL=1; return; fi
  if [ "$marker" != "-" ] && ! grep -qF "$marker" <<<"$body"; then
    echo "FAIL $path -> missing marker: $marker"; FAIL=1; return
  fi
  echo "ok   $path ($code)"
}

check / 200 -
check /schedule    200 -
check /standings   200 -
check /news        200 -
check /account     "200|302" -
check /nonexistent-page-xyz 404 -
check /arl-league-info 200 -
check /faqs 200 -
check "/?s=hockey" 200 -

# SportsPress entity pages (Task 8) -- real slugs discovered via
# `swp post list --post_type=sp_event|sp_player|sp_team --posts_per_page=1 --field=post_name`
check /event/116493 200 -
check /player/alec-lehto 200 -
check /team/blue-liners 200 -
# Additional entity types the brief's verification list calls out
# (staff page, venue archive) beyond the three literal Step 5 commands.
check /staff/zoe 200 -
check /venue/red 200 -

exit $FAIL
