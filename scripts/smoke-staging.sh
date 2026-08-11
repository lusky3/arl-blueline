#!/usr/bin/env bash
# HTTP smoke tests against staging. Usage: ./scripts/smoke-staging.sh
set -uo pipefail
BASE="${BASE:-https://staging.rookiehockey.ca}"
FAIL=0

check() { # check <path> <expected-http-code(s), "|"-separated> <must-contain-marker-or--> <must-NOT-contain-marker-or-->
  local path="$1" want="$2" marker="${3:--}" absent="${4:--}" body code ok=0 w
  body="$(curl -sS --max-time 30 -w '\n%{http_code}' "$BASE$path" 2>/dev/null)" || { echo "FAIL $path (curl error)"; FAIL=1; return; }
  code="${body##*$'\n'}"; body="${body%$'\n'*}"
  for w in ${want//|/ }; do
    [ "$code" = "$w" ] && { ok=1; break; }
  done
  if [ "$ok" -ne 1 ]; then echo "FAIL $path -> HTTP $code (want $want)"; FAIL=1; return; fi
  if [ "$marker" != "-" ] && ! grep -qF "$marker" <<<"$body"; then
    echo "FAIL $path -> missing marker: $marker"; FAIL=1; return
  fi
  if [ "$absent" != "-" ] && grep -qF "$absent" <<<"$body"; then
    echo "FAIL $path -> forbidden marker present: $absent"; FAIL=1; return
  fi
  echo "ok   $path ($code)"
}

# The homepage's <main id="main" ...> must carry tabindex="-1" -- Task 16
# found the skip link non-functional without it (activating it scrolled the
# page but never moved focus off <body>). This is the exact literal tag
# template-homepage.php emits; a future template edit that drops the
# attribute breaks this line.
check / 200 '<main id="main" class="bl-main bl-main--homepage" tabindex="-1">'
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

# The simple-css plugin's option is theme-independent and survives a
# redeploy of this repo -- these two guard against the exact regression
# that would occur if its original, pre-Task-16 CSS were ever restored
# (or applied unpruned to another environment): every WooCommerce button
# forced to the old brand's blue, and WooCommerce's own success notices
# hidden site-wide. Checked on a real WooCommerce/SportsPress registration
# product page, per the Task 16 review's own suggestion. (Note: SportsPress
# has an unrelated, pre-existing, fully-commented-out "Custom CSS" snippet
# containing the old brand's OTHER hex, #032867, on this same page -- inert,
# out of this project's scope, and deliberately not the hex checked here.)
check /registration/player-registration-w2026-27 200 - 0577da
check /registration/player-registration-w2026-27 200 - woocommerce-message

exit $FAIL
