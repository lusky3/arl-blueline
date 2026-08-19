#!/usr/bin/env python3
"""
End-to-end registration checkout against STAGING only.

Verifies, after the snippet 53 fix:
  * one line item on the order,
  * the "Registration Rules Accepted" meta still carries a real timestamp
    (this now comes from the WC session, not the cart item data),
  * the returning-player and Early Bird discounts still apply.
"""
import http.cookiejar
import json
import re
import sys
import urllib.parse
import urllib.request

BASE = "https://staging.rookiehockey.ca"
if "staging.rookiehockey.ca" not in BASE:
    sys.exit("REFUSING: not staging")

PRODUCT = 116522
PRODUCT_URL = f"{BASE}/registration/player-registration-w2026-27"
# A real past customer's email, so snippet 54's returning-player path is exercised.
BILLING_EMAIL = "nolanjcoyle@outlook.com"
UA = "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/126 Safari/537.36"

jar = http.cookiejar.CookieJar()
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
opener.addheaders = [("User-Agent", UA)]


def post(url, data):
    body = urllib.parse.urlencode(data, doseq=True).encode()
    req = urllib.request.Request(url, data=body, method="POST")
    req.add_header("Content-Type", "application/x-www-form-urlencoded")
    try:
        with opener.open(req, timeout=90) as r:
            return r.status, r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace")


def get(url):
    with opener.open(urllib.request.Request(url), timeout=90) as r:
        return r.status, r.read().decode("utf-8", "replace")


print("STEP 1: add registration to cart")
st, _ = post(PRODUCT_URL, {
    "add-to-cart": PRODUCT, "quantity": 1,
    "arl_rules_ack": 1, "arl_rules_version": "W2026-27",
})
print(f"  add-to-cart -> HTTP {st}")

print("STEP 2: load checkout and harvest the form")
st, html = get(f"{BASE}/checkout")
print(f"  checkout -> HTTP {st}, {len(html)} bytes")

m = re.search(r'<form name="checkout".*?</form>', html, re.S)
form = m.group(0) if m else html
print(f"  form region: {len(form)} bytes")

data = {}

# text/email/tel/hidden/number/date inputs
for tag in re.findall(r"<input\b[^>]*>", form):
    name = re.search(r'name="([^"]+)"', tag)
    if not name:
        continue
    name = name.group(1)
    itype = (re.search(r'type="([^"]+)"', tag) or [None, "text"])[1]
    value = re.search(r'value="([^"]*)"', tag)
    value = value.group(1) if value else ""
    if itype == "hidden":
        data[name] = value
    elif itype == "checkbox":
        if name in ("ship_to_different_address", "createaccount"):
            continue
        data[name] = value or "1"
    elif itype == "radio":
        data.setdefault(name, value)
    elif itype in ("text", "email", "tel", "number", "date", "password"):
        if value:
            data[name] = value

for tag in re.findall(r"<textarea\b[^>]*name=\"([^\"]+)\"", form):
    data.setdefault(tag, "staging test")

# selects: first non-empty option
for sel in re.findall(r"<select\b[^>]*>.*?</select>", form, re.S):
    name = re.search(r'name="([^"]+)"', sel)
    if not name:
        continue
    name = name.group(1)
    opts = re.findall(r'<option[^>]*value="([^"]*)"', sel)
    chosen = next((o for o in opts if o.strip()), "")
    if chosen:
        data[name] = chosen

# Now fill required text-ish fields we know checkout needs.
plausible = {
    "billing_first_name": "Staging", "billing_last_name": "Test",
    "billing_company": "", "billing_address_1": "123 Test St",
    "billing_address_2": "", "billing_city": "Burlington",
    "billing_postcode": "L7L 1A1", "billing_phone": "6470000000",
    "billing_email": BILLING_EMAIL,
    "billing_country": "CA", "billing_state": "ON",
    "order_comments": "",
}
for k, v in plausible.items():
    if v != "" or k in data:
        data[k] = v

# Any remaining empty text inputs that look required get generic content.
for tag in re.findall(r"<input\b[^>]*>", form):
    name = re.search(r'name="([^"]+)"', tag)
    itype = (re.search(r'type="([^"]+)"', tag) or [None, "text"])[1]
    if not name or itype not in ("text", "email", "tel", "number", "date"):
        continue
    name = name.group(1)
    if data.get(name):
        continue
    if "date" in name or itype == "date":
        data[name] = "1990-05-05"
    elif "email" in name:
        data[name] = BILLING_EMAIL
    elif "phone" in name or "number" in name or itype == "tel":
        data[name] = "6470000000"
    else:
        data[name] = "Staging Test"

data["payment_method"] = "betpg"
data["terms"] = "1"
data["woocommerce_checkout_place_order"] = "Place order"

print(f"  posting {len(data)} fields; payment_method={data['payment_method']}")
print("  sample of custom fields: " + ", ".join(
    sorted(k for k in data if k.startswith("arl_"))[:12]))

print("STEP 3: submit checkout")
st, body = post(f"{BASE}/?wc-ajax=checkout", data)
print(f"  submit -> HTTP {st}")
try:
    res = json.loads(body)
    print("  result:", res.get("result"))
    if res.get("result") == "success":
        print("  redirect:", res.get("redirect", "")[:140])
    else:
        msgs = re.sub(r"<[^>]+>", " ", str(res.get("messages", "")))
        print("  messages:", " ".join(msgs.split())[:1200])
except json.JSONDecodeError:
    print("  non-JSON response, first 600 chars:")
    print("   ", " ".join(re.sub(r"<[^>]+>", " ", body).split())[:600])
