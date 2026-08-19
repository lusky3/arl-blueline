#!/usr/bin/env bash
# Directly exercise the section-5 one-registration-per-order guard.
set -uo pipefail
BASE="https://staging.rookiehockey.ca"
UA="Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/126 Safari/537.36"
JAR="$(mktemp)"; trap 'rm -f "$JAR"' EXIT
case "$BASE" in *staging.rookiehockey.ca*) : ;; *) echo "REFUSING: not staging"; exit 2;; esac
V=/var/lib/docker/volumes/staging_wp_data/_data

cartshow() { echo "  --- cart: $1 ---"; curl -sS -A "$UA" -b "$JAR" -c "$JAR" "$BASE/wp-json/wc/store/v1/cart" \
  | python3 -c '
import json,sys
c=json.load(sys.stdin)
print("      items_count:", c.get("items_count"))
for i in c.get("items", []): print("      key=%s id=%s qty=%s %s" % (i.get("key","")[:16], i.get("id"), i.get("quantity"), i.get("name")))
print("      total:", (c.get("totals") or {}).get("total_price"))'; }

echo "### 1: guest adds Player, creating a real WC session"
curl -sS -o /dev/null -w '  add -> HTTP %{http_code}\n' -A "$UA" -b "$JAR" -c "$JAR" \
  --data-urlencode 'add-to-cart=116522' --data-urlencode 'quantity=1' \
  --data-urlencode 'arl_rules_ack=1' --data-urlencode 'arl_rules_version=W2026-27' \
  "$BASE/registration/player-registration-w2026-27"
cartshow "after add"

echo
echo "### 2: extract the WC session customer id from the cookie jar"
CID=$(grep -o 'wp_woocommerce_session_[a-f0-9]*[[:space:]]*[^[:space:]]*' "$JAR" | awk '{print $2}' | head -1 | sed 's/%7C.*//; s/|.*//')
echo "  customer id: $CID"
[ -n "$CID" ] || { echo "  could not read session cookie"; exit 1; }

echo
echo "### 3: inject a SECOND registration (Goalie) under its own key — the post-merge state"
printf '%s' "$CID" | ssh -o BatchMode=yes staging-host "cat > $V/.cid && chown 33:33 $V/.cid"
ssh -o BatchMode=yes staging-host "swp eval-file /var/www/html/inject-second-reg.php --skip-themes 2>/dev/null" | sed 's/^/  /'
cartshow "after injection (guard has not run yet)"

echo
echo "### 4: load /checkout — woocommerce_check_cart_items fires, guard runs"
curl -sS -A "$UA" -b "$JAR" -c "$JAR" "$BASE/checkout" -o /tmp/co2.html -w '  checkout HTTP %{http_code}\n'
echo "  guard notice present: $(grep -c 'Only one registration can be purchased per order' /tmp/co2.html)"
echo "  line items rendered in the order table:"
python3 - <<'PY'
import re
html = open('/tmp/co2.html', encoding='utf-8', errors='replace').read()
m = re.search(r'<table[^>]*shop_table[^>]*>.*?</table>', html, re.S)
seg = m.group(0) if m else html
for name in sorted(set(re.findall(r'(Player Registration \(W2026-27\)|Goalie Registration \(W2026-27\))', seg))):
    print("      -", name, "x", len(re.findall(re.escape(name), seg)))
PY
cartshow "after checkout render"

echo
echo "### RESULT"
N=$(curl -sS -A "$UA" -b "$JAR" -c "$JAR" "$BASE/wp-json/wc/store/v1/cart" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("items_count","?"))')
echo "  final items_count: $N"
if [ "$N" = "1" ]; then echo "  >>> GUARD WORKS: two-registration cart trimmed to one"; else echo "  >>> GUARD FAILED (count=$N)"; fi
ssh -o BatchMode=yes staging-host "rm -f $V/.cid"
rm -f /tmp/co2.html
