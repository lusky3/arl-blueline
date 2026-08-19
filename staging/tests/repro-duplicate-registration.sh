#!/usr/bin/env bash
# Reproduce order 116704's duplicate registration on staging via the post-login
# saved-cart merge. Runs entirely against staging.rookiehockey.ca.
set -uo pipefail

BASE="https://staging.rookiehockey.ca"
PROD_GUARD="staging.rookiehockey.ca"
USER="arlcarttest"
PASS='StagingTest!2026'
UID_TEST=2434
PID=116522
UA="Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/126 Safari/537.36"

JAR_A="$(mktemp)"; JAR_B="$(mktemp)"
trap 'rm -f "$JAR_A" "$JAR_B"' EXIT

# Refuse to run if BASE is not staging — never point this at production.
case "$BASE" in *"$PROD_GUARD"*) : ;; *) echo "REFUSING: BASE is not staging"; exit 2;; esac

sshstaging() { ssh -o BatchMode=yes staging-host "$@"; }

login() {  # $1 = jar
	curl -sS -o /dev/null -A "$UA" -b "$1" -c "$1" \
		--data-urlencode "log=$USER" \
		--data-urlencode "pwd=$PASS" \
		--data-urlencode 'wp-submit=Log In' \
		--data-urlencode "redirect_to=$BASE/my-account/" \
		--data-urlencode 'testcookie=1' \
		"$BASE/wp-login.php"
	if grep -q 'wordpress_logged_in' "$1"; then echo "  login: OK"; else echo "  login: FAILED"; fi
}

add() {  # $1 = jar
	curl -sS -o /dev/null -w '  add-to-cart -> HTTP %{http_code} redirect=%{redirect_url}\n' \
		-A "$UA" -b "$1" -c "$1" \
		--data-urlencode "add-to-cart=$PID" \
		--data-urlencode 'quantity=1' \
		--data-urlencode 'arl_rules_ack=1' \
		--data-urlencode 'arl_rules_version=W2026-27' \
		"$BASE/registration/player-registration-w2026-27"
}

cart() {  # $1 = jar, $2 = label
	echo "  --- cart ($2) ---"
	curl -sS -A "$UA" -b "$1" -c "$1" "$BASE/wp-json/wc/store/v1/cart" \
	| python3 -c '
import json,sys
try: c=json.load(sys.stdin)
except Exception: print("      (unparseable cart response)"); sys.exit()
print("      items_count:", c.get("items_count"))
for i in c.get("items", []):
    print("      key=%s id=%s qty=%s" % (i.get("key","")[:16], i.get("id"), i.get("quantity")))
print("      total:", (c.get("totals") or {}).get("total_price"))
'
}

persistent_cart() {
	echo "  --- persistent cart usermeta for user $UID_TEST ---"
	sshstaging "sdb -N -e \"SELECT LEFT(meta_value,300) FROM wp_usermeta WHERE user_id=$UID_TEST AND meta_key='_woocommerce_persistent_cart_1';\" 2>/dev/null" \
		| sed 's/^/      /' | head -4
}

echo "############ STEP 0: clean slate ############"
sshstaging "sdb -e \"DELETE FROM wp_usermeta WHERE user_id=$UID_TEST AND meta_key='_woocommerce_persistent_cart_1'; DELETE FROM wp_woocommerce_sessions WHERE session_key='$UID_TEST';\" 2>/dev/null" >/dev/null
echo "  cleared persistent cart + WC session for user $UID_TEST"

echo
echo "############ STEP 1: logged-in add (this is 'Aug 9 evening') ############"
login "$JAR_A"
add "$JAR_A"
cart "$JAR_A" "session A, logged in"
persistent_cart

echo
echo "############ STEP 2: simulate that session going away, keeping the saved cart ############"
sshstaging "sdb -e \"DELETE FROM wp_woocommerce_sessions WHERE session_key='$UID_TEST';\" 2>/dev/null" >/dev/null
echo "  deleted WC session row for user $UID_TEST (persistent cart usermeta kept)"
persistent_cart

echo
echo "############ STEP 3: brand-new GUEST session adds again ('Aug 10 midday') ############"
sleep 2   # ensure current_time('mysql') differs -> different cart item key
add "$JAR_B"
cart "$JAR_B" "session B, guest"

echo
echo "############ STEP 4: guest logs in at checkout -> saved-cart merge ############"
login "$JAR_B"
cart "$JAR_B" "session B, after login"

echo
echo "############ RESULT ############"
COUNT=$(curl -sS -A "$UA" -b "$JAR_B" -c "$JAR_B" "$BASE/wp-json/wc/store/v1/cart" \
	| python3 -c 'import json,sys; print(json.load(sys.stdin).get("items_count","?"))' 2>/dev/null)
echo "  items_count after login merge: $COUNT"
if [ "$COUNT" = "2" ]; then
	echo "  >>> BUG REPRODUCED: two registrations in one cart despite 'Limit purchases to 1 item per order'"
elif [ "$COUNT" = "1" ]; then
	echo "  >>> only one item — bug NOT reproduced by this path"
else
	echo "  >>> unexpected count: $COUNT"
fi
