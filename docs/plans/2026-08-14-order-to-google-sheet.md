# Order → Google Sheet Sync Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this
> plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Subagent-driven execution is
> NOT used on this project.

**Goal:** Mirror every W2026-27 registration order into a Google Sheet as one row, keeping that row
current as the order's status and questionnaire fields change.

**Architecture:** A Code Snippet hooks three WooCommerce events and enqueues an Action Scheduler
job per order; the job POSTs a 19-field JSON payload to a Google Apps Script web app bound to the
sheet; the script upserts on Order ID. One direction only — the sheet is a mirror, never a source.

**Tech stack:** WordPress 6.9.6 / WooCommerce 11.0.0, Code Snippets 3.9.6, Action Scheduler
(already present, 2,671 rows), Google Apps Script, wp-cli over SSH on `production-host`.

**Spec:** `docs/specs/2026-08-14-order-to-google-sheet-design.md` — read it first; this plan argues
from it.

## Global Constraints

- **Production is live and registration is open.** 241 W2026-27 orders exist; ~240 orders arrived
  in the last 30 days. Nothing in this plan may risk checkout.
- **Code lives in Code Snippets, never an mu-plugin.** The triggers run on the checkout path; a
  fatal in an mu-plugin white-screens checkout, whereas Code Snippets deactivates the snippet.
- **Code Snippets stores PHP without the `<?php` tag.** Lint with the tag prepended:
  `printf '<?php\n' | cat - <file> | php -l`.
- **The database is authoritative for snippets, not this repo.** After the snippet is live, mirror
  it into `snippets/` and confirm with `./scripts/sync-snippets.sh` (must exit 0).
- **Secrets never enter the repo.** The webhook URL and secret live in `wp_options`
  (`arl_sheet_webhook_url`, `arl_sheet_secret`); the snippet reads them.
- **Signature is a query parameter**, `?sig=<hmac_sha256(raw_body, secret)>` — Apps Script cannot
  read request headers.
- **Column order is fixed** at 19 columns A–S, exactly as the spec's table defines. Payload is
  keyed by header name so a future column insert cannot shift data.
- Scope is controlled by options, not hardcoded: `arl_sheet_product_ids` (default `116522,116523`)
  and `arl_sheet_sku_prefix` (default `116522-`).
- **Staging only:** `script.google.com` must be allowlisted in `mu-plugins/00-staging-guard.php`.
  Production has no such guard.
- On `production-host`, always `scp` a script and `rm -f` it in the **same** command — stray files in `/tmp`
  trip suspicious-file alerts.

## File structure

| File | Responsibility |
|---|---|
| `snippets/pending/order-to-google-sheet.php` | The whole WordPress side: config accessors, payload builder, position derivation, scope check, triggers, worker, wp-cli commands. One snippet because Code Snippets is the unit of activation — splitting it would mean several snippets that must all be active together. |
| `google-apps-script/order-sheet-sync.gs` | The Apps Script: signature check, header-name→column mapping, upsert, lock. |
| `staging/00-staging-guard.php` | Modified: allow `script.google.com` on staging. |
| `snippets/<id>-order-to-google-sheet.php` | Created in Task 8 once the live snippet ID is known. |
| `snippets/manifest.tsv` | Updated in Task 8. |

---

## Task 1: Config, scope check, position derivation, payload builder

Pure functions, no network, no writes. This is the task most likely to contain a subtle mapping
error, so it is verified against real production orders before anything is wired up.

**Files:**
- Create: `snippets/pending/order-to-google-sheet.php`

**Interfaces produced (later tasks rely on these exact names):**
- `arl_sheet_columns() : array` — the 19 header strings in order
- `arl_sheet_option( string $key, $default = '' )` — reads `arl_sheet_{$key}`
- `arl_sheet_product_ids() : int[]`
- `arl_sheet_in_scope( WC_Order $order ) : bool`
- `arl_sheet_position_for_product( int $product_id ) : string`
- `arl_sheet_build_payload( int $order_id ) : array|null` — `array( 'order_id' => int, 'values' => array<string,string> )`

- [ ] **Step 1: Write the snippet body**

```php
/**
 * ARL: mirror W2026-27 registration orders into a Google Sheet.
 *
 * Spec: docs/specs/2026-08-14-order-to-google-sheet-design.md
 *
 * One row per order, keyed on Order ID in column A. The Apps Script upserts, so pushing the same
 * order repeatedly is harmless — that is what makes retries and backfill safe.
 */

/* ---------- config ---------- */

function arl_sheet_option( $key, $default = '' ) {
	$v = get_option( 'arl_sheet_' . $key, $default );
	return ( '' === $v || null === $v ) ? $default : $v;
}

function arl_sheet_product_ids() {
	$raw = arl_sheet_option( 'product_ids', '116522,116523' );
	return array_values( array_filter( array_map( 'intval', explode( ',', (string) $raw ) ) ) );
}

/* ---------- columns ---------- */

function arl_sheet_columns() {
	return array(
		'Order ID', 'Order Date', 'Product Name', 'Order Status',
		'First Name', 'Last Name', 'Email',
		'Gender', 'D.o.B.', 'Position', 'Experience', 'Division', 'Returning Player',
		'Restricted', 'Requested Team', 'Requested Partner',
		'Captain', 'Requested Partner 2', 'Requested Partner 3',
	);
}

/* ---------- scope ---------- */

function arl_sheet_in_scope( $order ) {
	if ( ! $order instanceof WC_Order ) { return false; }
	$ids    = arl_sheet_product_ids();
	$prefix = (string) arl_sheet_option( 'sku_prefix', '116522-' );
	foreach ( $order->get_items() as $item ) {
		$pid = (int) $item->get_product_id();
		if ( in_array( $pid, $ids, true ) ) { return true; }
		if ( '' !== $prefix ) {
			$sku = (string) get_post_meta( $pid, '_sku', true );
			if ( '' !== $sku && 0 === strpos( $sku, $prefix ) ) { return true; }
		}
	}
	return false;
}

/* ---------- Position: Player or Goalie, from the product ---------- */

function arl_sheet_position_for_product( $product_id ) {
	$tags = wp_get_post_terms( (int) $product_id, 'product_tag', array( 'fields' => 'ids' ) );
	if ( ! is_wp_error( $tags ) ) {
		if ( in_array( 201, array_map( 'intval', $tags ), true ) ) { return 'Player'; }
		if ( in_array( 202, array_map( 'intval', $tags ), true ) ) { return 'Goalie'; }
	}
	$sku = (string) get_post_meta( (int) $product_id, '_sku', true );
	if ( '' !== $sku ) {
		if ( preg_match( '/-WP$/i', $sku ) ) { return 'Waitlist Player'; }
		if ( preg_match( '/-P$/i', $sku ) )  { return 'Player'; }
		if ( preg_match( '/-G$/i', $sku ) )  { return 'Goalie'; }
	}
	arl_sheet_log( 'WARN', 0, 'position unresolved for product ' . (int) $product_id );
	return '';
}

/* ---------- payload ---------- */

function arl_sheet_build_payload( $order_id ) {
	$order = wc_get_order( (int) $order_id );
	if ( ! $order || ! arl_sheet_in_scope( $order ) ) { return null; }

	$ids       = arl_sheet_product_ids();
	$prefix    = (string) arl_sheet_option( 'sku_prefix', '116522-' );
	$names     = array();
	$positions = array();
	foreach ( $order->get_items() as $item ) {
		$pid = (int) $item->get_product_id();
		$sku = (string) get_post_meta( $pid, '_sku', true );
		$hit = in_array( $pid, $ids, true ) || ( '' !== $prefix && 0 === strpos( $sku, $prefix ) );
		if ( ! $hit ) { continue; }
		$names[]     = $item->get_name();
		$positions[] = arl_sheet_position_for_product( $pid );
	}
	$names     = array_values( array_unique( array_filter( $names ) ) );
	$positions = array_values( array_unique( array_filter( $positions ) ) );

	$created = $order->get_date_created();

	$values = array(
		'Order ID'            => (string) $order->get_id(),
		'Order Date'          => $created ? $created->date( 'Y-m-d H:i' ) : '',
		'Product Name'        => implode( ' + ', $names ),
		'Order Status'        => wc_get_order_status_name( $order->get_status() ),
		'First Name'          => (string) $order->get_billing_first_name(),
		'Last Name'           => (string) $order->get_billing_last_name(),
		'Email'               => (string) $order->get_billing_email(),
		'Gender'              => (string) $order->get_meta( 'arl_gender' ),
		'D.o.B.'              => (string) $order->get_meta( 'arl_dob' ),
		'Position'            => implode( ' + ', $positions ),
		'Experience'          => (string) $order->get_meta( 'arl_experience' ),
		'Division'            => (string) $order->get_meta( 'arl_division' ),
		'Returning Player'    => (string) $order->get_meta( 'arl_returning' ),
		'Restricted'          => (string) $order->get_meta( 'arl_waitlist_restrictions' ),
		'Requested Team'      => (string) $order->get_meta( 'arl_team' ),
		'Requested Partner'   => (string) $order->get_meta( 'arl_request' ),
		'Captain'             => (string) $order->get_meta( 'arl_captain' ),
		'Requested Partner 2' => (string) $order->get_meta( 'arl_request2' ),
		'Requested Partner 3' => (string) $order->get_meta( 'arl_request3' ),
	);

	// Guarantee every column exists and nothing extra sneaks in.
	$ordered = array();
	foreach ( arl_sheet_columns() as $col ) {
		$ordered[ $col ] = isset( $values[ $col ] ) ? (string) $values[ $col ] : '';
	}

	return array( 'order_id' => (int) $order->get_id(), 'values' => $ordered );
}

/* ---------- logging ---------- */

function arl_sheet_log( $level, $order_id, $message ) {
	$line = sprintf( "[%s] %-5s order=%s %s\n", gmdate( 'Y-m-d H:i:s' ), $level, $order_id, $message );
	@file_put_contents( WP_CONTENT_DIR . '/arl-sheet-sync.log', $line, FILE_APPEND );
}
```

- [ ] **Step 2: Lint it**

```bash
cd ~/git/rookiehockey.ca
printf '<?php\n' | cat - snippets/pending/order-to-google-sheet.php > /tmp/lint.php && php -l /tmp/lint.php; rm -f /tmp/lint.php
```

Expected: `No syntax errors detected`. If `php` is unavailable locally, lint on `production-host` the same way.

- [ ] **Step 3: Write the verification script**

Create `/tmp/t1-verify.php` locally with this content. It loads the snippet's functions directly
(the snippet is not installed yet) and asserts against three real production orders.

```php
<?php
require_once '/tmp/arl-sheet-fns.php';   // the snippet body with <?php prepended

$fail = 0;
function check( $label, $got, $want ) {
	global $fail;
	$ok = ( $got === $want );
	if ( ! $ok ) { $fail++; }
	printf( "  %s %-42s got=%-34s want=%s\n", $ok ? 'PASS' : 'FAIL', $label,
		'"' . $got . '"', '"' . $want . '"' );
}

// 116777 = Richard Peters, Player Registration, Returning (corrected earlier today)
$p = arl_sheet_build_payload( 116777 );
echo "=== order 116777 ===\n";
check( 'Order ID',          $p['values']['Order ID'], '116777' );
check( 'Position',          $p['values']['Position'], 'Player' );
check( 'Returning Player',  $p['values']['Returning Player'], 'Returning' );
check( 'Order Status',      $p['values']['Order Status'], 'Completed' );
check( 'Requested Partner', $p['values']['Requested Partner'], 'Andrew McRorie' );
check( 'Captain',           $p['values']['Captain'], 'No' );
check( 'Restricted blank',  $p['values']['Restricted'], '' );
check( 'column count',      (string) count( $p['values'] ), '19' );
check( 'key order intact',  implode( ',', array_keys( $p['values'] ) ), implode( ',', arl_sheet_columns() ) );

// 116724 = Max Yermakhanov, Requested Partner edited to Andrew Booker earlier today
$p = arl_sheet_build_payload( 116724 );
echo "=== order 116724 ===\n";
check( 'Requested Partner', $p['values']['Requested Partner'], 'Andrew Booker' );
check( 'Requested Team',    $p['values']['Requested Team'], 'Train Wreck' );

// a goalie order — find one, assert Position
global $wpdb;
$goalie = (int) $wpdb->get_var( "SELECT i.order_id FROM {$wpdb->prefix}woocommerce_order_items i
  JOIN {$wpdb->prefix}woocommerce_order_itemmeta m ON m.order_item_id=i.order_item_id AND m.meta_key='_product_id'
  WHERE m.meta_value='116523' ORDER BY i.order_id DESC LIMIT 1" );
$p = arl_sheet_build_payload( $goalie );
echo "=== goalie order {$goalie} ===\n";
check( 'Position', $p['values']['Position'], 'Goalie' );

// out of scope: an old W2025-26 order must return null
echo "=== scope ===\n";
check( 'W2025-26 order excluded', var_export( arl_sheet_build_payload( 114429 ), true ), 'NULL' );

echo $fail ? "\n*** {$fail} FAILURES ***\n" : "\nall assertions passed\n";
```

- [ ] **Step 4: Run it against production, read-only**

```bash
cd ~/git/rookiehockey.ca
printf '<?php\n' | cat - snippets/pending/order-to-google-sheet.php > /tmp/arl-sheet-fns.php
scp -q /tmp/arl-sheet-fns.php /tmp/t1-verify.php production-host:/tmp/
ssh production-host 'sudo -u www-data /usr/local/bin/wp --path=/var/www/rookiehockey.ca/htdocs \
  eval-file /tmp/t1-verify.php --user=9 --skip-themes 2>/dev/null; rm -f /tmp/t1-verify.php /tmp/arl-sheet-fns.php'
rm -f /tmp/arl-sheet-fns.php
```

Expected: every line `PASS`, ending `all assertions passed`. A `FAIL` on Position means the
product-tag lookup is wrong; a `FAIL` on `key order intact` means the column list drifted from
`arl_sheet_columns()`.

---

## Task 2: Apps Script, deployed and smoke-tested

**Files:**
- Create: `google-apps-script/order-sheet-sync.gs`

**Interfaces produced:** a `/exec` URL that accepts `POST ?sig=<hex>` with the Task 1 payload and
returns `{"ok":true,"row":N}`.

- [ ] **Step 1: Write the Apps Script**

```javascript
/**
 * ARL order sync — receives one order from WordPress and upserts it as a row.
 *
 * Setup:
 *   1. Extensions -> Apps Script, paste this file.
 *   2. Project Settings -> Script properties -> add ARL_SHEET_SECRET = <the same secret as WordPress>.
 *   3. Deploy -> New deployment -> Web app, "Execute as: me", "Who has access: Anyone".
 *   4. Give the /exec URL to WordPress (option arl_sheet_webhook_url).
 *
 * Security: the /exec URL is world-reachable, so every request must carry
 * ?sig=hex(hmac_sha256(rawBody, ARL_SHEET_SECRET)). Requests without a valid signature are refused.
 */

var SHEET_NAME = 'Registrations';

function doPost(e) {
  var lock = LockService.getScriptLock();
  lock.waitLock(30000);          // two pushes for one order must not both append
  try {
    var secret = PropertiesService.getScriptProperties().getProperty('ARL_SHEET_SECRET');
    if (!secret) { return json({ ok: false, error: 'secret not configured' }); }

    var body = e.postData ? e.postData.contents : '';
    var given = ((e.parameter && e.parameter.sig) || '').toLowerCase();
    if (given !== hmacHex(body, secret)) {
      return json({ ok: false, error: 'bad signature' });
    }

    var payload = JSON.parse(body);
    var values = payload.values || {};
    var sheet = getSheet();
    var headers = sheet.getRange(1, 1, 1, sheet.getLastColumn()).getValues()[0];

    var row = new Array(headers.length).fill('');
    for (var i = 0; i < headers.length; i++) {
      var h = headers[i];
      if (Object.prototype.hasOwnProperty.call(values, h)) { row[i] = values[h]; }
    }

    var target = findRowByOrderId(sheet, String(payload.order_id));
    if (target > 0) {
      sheet.getRange(target, 1, 1, headers.length).setValues([row]);
    } else {
      sheet.appendRow(row);
      target = sheet.getLastRow();
    }
    return json({ ok: true, row: target });
  } catch (err) {
    return json({ ok: false, error: String(err) });
  } finally {
    lock.releaseLock();
  }
}

function getSheet() {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  var sheet = ss.getSheetByName(SHEET_NAME);
  if (!sheet) {
    sheet = ss.insertSheet(SHEET_NAME);
  }
  if (sheet.getLastRow() === 0) {
    sheet.appendRow([
      'Order ID', 'Order Date', 'Product Name', 'Order Status',
      'First Name', 'Last Name', 'Email',
      'Gender', 'D.o.B.', 'Position', 'Experience', 'Division', 'Returning Player',
      'Restricted', 'Requested Team', 'Requested Partner',
      'Captain', 'Requested Partner 2', 'Requested Partner 3'
    ]);
    sheet.setFrozenRows(1);
  }
  return sheet;
}

function findRowByOrderId(sheet, orderId) {
  var last = sheet.getLastRow();
  if (last < 2) { return 0; }
  var ids = sheet.getRange(2, 1, last - 1, 1).getValues();
  for (var i = 0; i < ids.length; i++) {
    if (String(ids[i][0]).trim() === orderId) { return i + 2; }
  }
  return 0;
}

function hmacHex(message, key) {
  var raw = Utilities.computeHmacSha256Signature(message, key);
  return raw.map(function (b) {
    var v = (b < 0 ? b + 256 : b).toString(16);
    return v.length === 1 ? '0' + v : v;
  }).join('');
}

function json(obj) {
  return ContentService.createTextOutput(JSON.stringify(obj))
    .setMimeType(ContentService.MimeType.JSON);
}
```

- [ ] **Step 2: Cody deploys it**

Hand over `google-apps-script/order-sheet-sync.gs` with the four setup steps in its header. Collect
the `/exec` URL and the secret. Nothing further in this plan can proceed without both.

- [ ] **Step 3: Smoke-test the deployment from the command line**

```bash
URL='<the /exec URL>'
SECRET='<the secret>'
BODY='{"order_id":999999,"values":{"Order ID":"999999","Order Date":"2026-01-01 00:00","Product Name":"SMOKE TEST","Order Status":"Test","First Name":"Smoke","Last Name":"Test","Email":"smoke@example.invalid","Gender":"","D.o.B.":"","Position":"Player","Experience":"","Division":"","Returning Player":"","Restricted":"","Requested Team":"","Requested Partner":"","Captain":"","Requested Partner 2":"","Requested Partner 3":""}}'
SIG=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "$SECRET" -hex | awk '{print $2}')
curl -sSL -X POST "$URL?sig=$SIG" -H 'Content-Type: application/json' -d "$BODY"
```

Expected: `{"ok":true,"row":2}`.

- [ ] **Step 4: Verify the upsert and the signature check**

Re-run the identical command. Expected: `{"ok":true,"row":2}` again — the **same** row number, and
the sheet still has exactly one row for 999999. That single observation is the core requirement of
this whole feature.

Then tamper with the signature:

```bash
curl -sSL -X POST "$URL?sig=deadbeef" -H 'Content-Type: application/json' -d "$BODY"
```

Expected: `{"ok":false,"error":"bad signature"}`.

- [ ] **Step 5: Delete the smoke-test row**

Delete row 2 (order 999999) from the sheet by hand, so the backfill starts clean.

---

## Task 3: Worker and transport, proven on staging

**Files:**
- Modify: `snippets/pending/order-to-google-sheet.php` (append the worker)
- Modify: `staging/00-staging-guard.php` (allowlist `script.google.com`)

**Interfaces consumed:** `arl_sheet_build_payload()`, `arl_sheet_log()` from Task 1.
**Interfaces produced:** `arl_sheet_push_order( int $order_id ) : bool` — throws on failure so
Action Scheduler retries.

- [ ] **Step 1: Allowlist the Apps Script host on staging only**

In `staging/00-staging-guard.php`, inside the `pre_http_request` filter, before the deny loop:

```php
	// Deliberate, staging-only exception: the order->Sheet sync must reach Apps Script.
	// See docs/specs/2026-08-14-order-to-google-sheet-design.md. Production has no guard.
	if ( 'script.google.com' === $host || 'script.googleusercontent.com' === $host ) {
		arl_staging_guard_log( 'HTTP ALLOW', $host . ' (order sheet sync)' );
		return $preempt;
	}
```

`script.googleusercontent.com` is required too: an Apps Script web app 302-redirects there to
return its body.

- [ ] **Step 2: Append the worker to the snippet**

```php
/* ---------- worker ---------- */

function arl_sheet_push_order( $order_id ) {
	$url    = (string) arl_sheet_option( 'webhook_url', '' );
	$secret = (string) arl_sheet_option( 'secret', '' );
	if ( '' === $url || '' === $secret ) {
		arl_sheet_log( 'SKIP', $order_id, 'webhook_url or secret not configured' );
		return false;
	}

	$payload = arl_sheet_build_payload( $order_id );
	if ( null === $payload ) {
		arl_sheet_log( 'SKIP', $order_id, 'out of scope or order missing' );
		return false;
	}

	$body = wp_json_encode( $payload );
	$sig  = hash_hmac( 'sha256', $body, $secret );

	$res = wp_remote_post( add_query_arg( 'sig', $sig, $url ), array(
		'timeout'     => 15,
		'redirection' => 5,          // Apps Script 302s to script.googleusercontent.com
		'headers'     => array( 'Content-Type' => 'application/json' ),
		'body'        => $body,
	) );

	if ( is_wp_error( $res ) ) {
		arl_sheet_log( 'ERR', $order_id, 'transport: ' . $res->get_error_message() );
		throw new Exception( 'arl_sheet transport failure: ' . $res->get_error_message() );
	}

	$code = (int) wp_remote_retrieve_response_code( $res );
	$raw  = (string) wp_remote_retrieve_body( $res );
	$json = json_decode( $raw, true );

	if ( 200 !== $code || empty( $json['ok'] ) ) {
		arl_sheet_log( 'ERR', $order_id, 'http ' . $code . ' body ' . mb_substr( $raw, 0, 200 ) );
		throw new Exception( 'arl_sheet rejected: http ' . $code );
	}

	$order = wc_get_order( $order_id );
	if ( $order ) {
		$order->update_meta_data( '_arl_sheet_pushed_at', current_time( 'mysql' ) );
		$order->update_meta_data( '_arl_sheet_row', (int) ( $json['row'] ?? 0 ) );
		$order->save();
	}
	arl_sheet_log( 'OK', $order_id, 'row ' . (int) ( $json['row'] ?? 0 ) );
	return true;
}
```

- [ ] **Step 3: Install on staging pointing at a throwaway sheet**

Deploy a second Apps Script bound to a **test** spreadsheet, then:

```bash
ssh staging-host 'STAGING_DB_PASS=<see compose.yml> swp option update arl_sheet_webhook_url "<TEST /exec URL>"'
ssh staging-host 'STAGING_DB_PASS=<see compose.yml> swp option update arl_sheet_secret "<test secret>"'
```

Install the snippet on staging with `scripts/deploy-snippet.php` against a new snippet row, then
`swp cache flush`.

- [ ] **Step 4: Push one order synchronously and confirm the row**

```bash
ssh staging-host 'STAGING_DB_PASS=<...> swp eval "var_dump( arl_sheet_push_order( 116777 ) );" --skip-themes'
```

Expected: `bool(true)`, one new row in the test sheet with Richard Peters' data, `OK` in
`wp-content/arl-sheet-sync.log`, and `_arl_sheet_pushed_at` set on the order. Then confirm the
staging guard logged `HTTP ALLOW script.google.com` rather than a deny.

---

## Task 4: Triggers, and the five behaviours that matter

**Files:**
- Modify: `snippets/pending/order-to-google-sheet.php` (append the triggers)

**Interfaces consumed:** `arl_sheet_in_scope()`, `arl_sheet_push_order()`.

- [ ] **Step 1: Append the triggers**

```php
/* ---------- triggers ---------- */

function arl_sheet_enqueue( $order_id, $extra = null ) {
	$order_id = (int) $order_id;
	if ( ! $order_id ) { return; }
	if ( ! function_exists( 'as_enqueue_async_action' ) ) {
		arl_sheet_log( 'ERR', $order_id, 'Action Scheduler unavailable' );
		return;
	}
	$order = wc_get_order( $order_id );
	if ( ! $order || ! arl_sheet_in_scope( $order ) ) { return; }

	// Collapse a burst of edits into one push.
	if ( function_exists( 'as_has_scheduled_action' )
		&& as_has_scheduled_action( 'arl_sheet_push', array( $order_id ), 'arl-sheet' ) ) {
		return;
	}
	as_enqueue_async_action( 'arl_sheet_push', array( $order_id ), 'arl-sheet' );
}

add_action( 'woocommerce_new_order', 'arl_sheet_enqueue', 10, 1 );
add_action( 'woocommerce_order_status_changed', 'arl_sheet_enqueue', 10, 1 );
// Priority 99: Checkout Field Editor Pro must have written its fields before we read them.
add_action( 'woocommerce_process_shop_order_meta', 'arl_sheet_enqueue', 99, 1 );

add_action( 'arl_sheet_push', 'arl_sheet_push_order', 10, 1 );
```

- [ ] **Step 2: Test — a new registration appends exactly one row**

On staging, run a registration through checkout using
`staging/tests/checkout-end-to-end.py`. Then:

```bash
ssh staging-host 'STAGING_DB_PASS=<...> swp action-scheduler run --group=arl-sheet'
```

Expected: one new row in the test sheet. Note its row number.

- [ ] **Step 3: Test — a status change updates that row and does NOT add another**

```bash
ssh staging-host 'STAGING_DB_PASS=<...> swp eval "\$o=wc_get_order(<new order id>); \$o->update_status(\"on-hold\"); " --skip-themes'
ssh staging-host 'STAGING_DB_PASS=<...> swp action-scheduler run --group=arl-sheet'
```

Expected: the **same** row number, Order Status now `On hold`, and the sheet's row count unchanged.
This is the requirement the whole feature exists for — if the row count grows, stop and fix the
`findRowByOrderId` comparison (most likely the sheet is storing Order ID as a number while the
script compares strings).

- [ ] **Step 4: Test — a questionnaire edit updates the row**

```bash
ssh staging-host 'STAGING_DB_PASS=<...> swp eval "\$o=wc_get_order(<id>); \$o->update_meta_data(\"arl_request\",\"Trigger Test\"); \$o->save(); do_action(\"woocommerce_process_shop_order_meta\", <id>);" --skip-themes'
ssh staging-host 'STAGING_DB_PASS=<...> swp action-scheduler run --group=arl-sheet'
```

Expected: same row, Requested Partner now `Trigger Test`.

- [ ] **Step 5: Test — pushing twice is harmless, and out-of-scope orders are ignored**

```bash
ssh staging-host 'STAGING_DB_PASS=<...> swp eval "arl_sheet_push_order(<id>); arl_sheet_push_order(<id>);" --skip-themes'
ssh staging-host 'STAGING_DB_PASS=<...> swp eval "arl_sheet_enqueue(114429);" --skip-themes'
```

Expected: still one row for that order; nothing enqueued for 114429 (a W2025-26 order), and a
`SKIP ... out of scope` line only if `arl_sheet_push_order` was called on it directly.

---

## Task 5: wp-cli commands

**Files:**
- Modify: `snippets/pending/order-to-google-sheet.php` (append the CLI block)

**Interfaces consumed:** `arl_sheet_push_order()`, `arl_sheet_product_ids()`.

- [ ] **Step 1: Append the commands**

```php
/* ---------- wp-cli ---------- */

if ( defined( 'WP_CLI' ) && WP_CLI ) {

	WP_CLI::add_command( 'arl sheet:sync', function ( $args, $assoc ) {
		global $wpdb;
		if ( ! empty( $args[0] ) ) {
			$ids = array( (int) $args[0] );
		} else {
			$pids = implode( ',', arl_sheet_product_ids() );
			$ids  = $wpdb->get_col( "SELECT DISTINCT i.order_id
				FROM {$wpdb->prefix}woocommerce_order_items i
				JOIN {$wpdb->prefix}woocommerce_order_itemmeta m
				  ON m.order_item_id = i.order_item_id AND m.meta_key = '_product_id'
				WHERE m.meta_value IN ({$pids}) ORDER BY i.order_id" );
		}
		$force = ! empty( $assoc['force'] );
		$done = 0; $skipped = 0; $failed = 0; $n = 0;
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order ) { continue; }
			if ( ! $force ) {
				$pushed = $order->get_meta( '_arl_sheet_pushed_at' );
				if ( $pushed && strtotime( $pushed ) >= strtotime( $order->get_date_modified()->date( 'Y-m-d H:i:s' ) ) ) {
					$skipped++;
					continue;
				}
			}
			try {
				arl_sheet_push_order( $id ) ? $done++ : $skipped++;
			} catch ( Exception $e ) {
				$failed++;
				WP_CLI::warning( $id . ': ' . $e->getMessage() );
			}
			if ( 0 === ++$n % 50 ) { WP_CLI::log( "  ...{$n} processed" ); sleep( 2 ); }
		}
		WP_CLI::success( "pushed {$done}, skipped {$skipped}, failed {$failed}" );
	} );

	WP_CLI::add_command( 'arl sheet:status', function () {
		global $wpdb;
		$pids = implode( ',', arl_sheet_product_ids() );
		$ids  = $wpdb->get_col( "SELECT DISTINCT i.order_id
			FROM {$wpdb->prefix}woocommerce_order_items i
			JOIN {$wpdb->prefix}woocommerce_order_itemmeta m
			  ON m.order_item_id = i.order_item_id AND m.meta_key = '_product_id'
			WHERE m.meta_value IN ({$pids})" );
		$pushed = 0; $stale = 0; $never = 0;
		foreach ( $ids as $id ) {
			$o = wc_get_order( $id );
			if ( ! $o ) { continue; }
			$at = $o->get_meta( '_arl_sheet_pushed_at' );
			if ( ! $at ) { $never++; }
			elseif ( strtotime( $at ) < strtotime( $o->get_date_modified()->date( 'Y-m-d H:i:s' ) ) ) { $stale++; }
			else { $pushed++; }
		}
		WP_CLI::log( 'in-scope orders: ' . count( $ids ) );
		WP_CLI::log( "  up to date: {$pushed}" );
		WP_CLI::log( "  stale (edited since push): {$stale}" );
		WP_CLI::log( "  never pushed: {$never}" );
		$log = WP_CONTENT_DIR . '/arl-sheet-sync.log';
		if ( file_exists( $log ) ) {
			WP_CLI::log( "\nlast 10 log lines:" );
			foreach ( array_slice( file( $log ), -10 ) as $l ) { WP_CLI::log( '  ' . rtrim( $l ) ); }
		}
	} );
}
```

- [ ] **Step 2: Verify both commands on staging**

```bash
ssh staging-host 'STAGING_DB_PASS=<...> swp arl sheet:status'
ssh staging-host 'STAGING_DB_PASS=<...> swp arl sheet:sync 116777'
```

Expected: `sheet:status` prints the three counts and log tail; `sheet:sync 116777` reports
`pushed 1` and the sheet row updates. Run `sheet:sync 116777` again — expect `skipped 1`, proving
the freshness check works without `--force`.

---

## Task 6: Production deploy

**Files:** none new — installs the Task 1–5 snippet on production.

- [ ] **Step 1: Set the production options**

```bash
ssh production-host 'sudo -u www-data /usr/local/bin/wp --path=/var/www/rookiehockey.ca/htdocs \
  option update arl_sheet_webhook_url "<PROD /exec URL>" --user=9 --skip-themes'
ssh production-host 'sudo -u www-data /usr/local/bin/wp --path=/var/www/rookiehockey.ca/htdocs \
  option update arl_sheet_secret "<PROD secret>" --user=9 --skip-themes'
```

- [ ] **Step 2: Lint, then insert the snippet INACTIVE**

Insert with `active = 0` and `scope = 'global'` (the CLI commands and the admin-edit hook both need
it outside front-end requests). Capture the returned snippet ID — later steps and Task 8 need it.

```bash
ssh production-host 'sudo -u www-data /usr/local/bin/wp --path=/var/www/rookiehockey.ca/htdocs eval "
global \$wpdb;
\$code = file_get_contents( \"/tmp/arl-sheet.php\" );
\$wpdb->insert( \$wpdb->prefix.\"snippets\", array(
  \"name\" => \"Order to Google Sheet sync\", \"code\" => \$code, \"scope\" => \"global\",
  \"active\" => 0, \"priority\" => 10, \"desc\" => \"Mirrors W2026-27 registrations into a Google Sheet\",
) );
echo \"snippet id: \", \$wpdb->insert_id, PHP_EOL;
" --user=9 --skip-themes'
```

- [ ] **Step 3: Activate, flush, and confirm no fatal**

```bash
ssh production-host 'sudo -u www-data /usr/local/bin/wp --path=/var/www/rookiehockey.ca/htdocs eval "
global \$wpdb; \$wpdb->update( \$wpdb->prefix.\"snippets\", array(\"active\"=>1), array(\"id\"=><ID>) );
wp_cache_flush(); echo \"active\", PHP_EOL;" --user=9 --skip-themes'
ssh production-host 'sudo -u www-data /usr/local/bin/wp --path=/var/www/rookiehockey.ca/htdocs eval "
global \$wpdb; echo \"still active: \", \$wpdb->get_var(\"SELECT active FROM {\$wpdb->prefix}snippets WHERE id=<ID>\"), PHP_EOL;
echo \"functions loaded: \", var_export( function_exists(\"arl_sheet_build_payload\"), true ), PHP_EOL;" --user=9 --skip-themes'
```

Expected: `still active: 1` and `functions loaded: true`. If `active` has flipped to `0`, Code
Snippets trapped a fatal — read `wp-content/debug.log`, fix, and repeat.

- [ ] **Step 4: Verify the site is unharmed**

```bash
for u in / /register /checkout /standings; do
  curl -sSL -o /dev/null -w "  $u -> %{http_code}\n" --max-time 30 "https://www.rookiehockey.ca$u"
done
```

Expected: 200, 200, 302, 200 — same as before the deploy.

- [ ] **Step 5: Push one real order and confirm it lands**

```bash
ssh production-host 'sudo -u www-data /usr/local/bin/wp --path=/var/www/rookiehockey.ca/htdocs \
  arl sheet:sync 116777 --user=9 --skip-themes'
```

Expected: `pushed 1`, and Richard Peters appears in the production sheet with Position `Player`.

---

## Task 7: Backfill the 241 existing orders

- [ ] **Step 1: Dry-run the count**

```bash
ssh production-host 'sudo -u www-data /usr/local/bin/wp --path=/var/www/rookiehockey.ca/htdocs \
  arl sheet:status --user=9 --skip-themes'
```

Expected: `never pushed: 240` (241 minus the one from Task 6).

- [ ] **Step 2: Run the backfill**

```bash
ssh production-host 'sudo -u www-data /usr/local/bin/wp --path=/var/www/rookiehockey.ca/htdocs \
  arl sheet:sync --user=9 --skip-themes'
```

Batches of 50 with a 2-second pause. Expect `pushed 240, skipped 1, failed 0`.

- [ ] **Step 3: Verify row count equals order count**

```bash
ssh production-host 'sudo -u www-data /usr/local/bin/wp --path=/var/www/rookiehockey.ca/htdocs \
  arl sheet:status --user=9 --skip-themes'
```

Expected: `up to date: 241`, `stale: 0`, `never pushed: 0`. Then check the sheet has **242 rows**
(241 orders + the frozen header) and no duplicate Order IDs — in the sheet,
`=COUNTA(A2:A)-COUNTA(UNIQUE(A2:A))` must be `0`.

---

## Task 8: Mirror to the repo and document

- [ ] **Step 1: Pull the live snippet into the repo under its real ID**

```bash
cd ~/git/rookiehockey.ca
ID=<snippet id from Task 6>
ssh production-host "sudo -u www-data /usr/local/bin/wp --path=/var/www/rookiehockey.ca/htdocs eval '
global \$wpdb; echo \$wpdb->get_var(\"SELECT code FROM {\$wpdb->prefix}snippets WHERE id=${ID}\");
' --user=9 --skip-themes" > snippets/${ID}-order-to-google-sheet.php
rm -f snippets/pending/order-to-google-sheet.php
```

- [ ] **Step 2: Add it to the manifest and confirm the mirror matches production**

Append the row to `snippets/manifest.tsv`:
`<ID>\tOrder to Google Sheet sync\tglobal\t1\t10`

```bash
./scripts/sync-snippets.sh
```

Expected: the new file listed `ok`, and `in sync with production-host`, exit 0.

- [ ] **Step 3: Record the change in the changelog**

Append a section to `CHANGES-2026-08.md` covering: the 19 columns and their source fields, that
Position is derived from `product_tag` 201/202 with SKU fallback, the two options holding the URL
and secret (naming them but never their values), the `wp arl sheet:sync` / `sheet:status` commands,
the staging-guard exception for `script.google.com`, and that the sheet is a one-way mirror.

- [ ] **Step 4: Note the staging-guard hole in the staging README**

In `staging/README.md`, under the containment section, record that `script.google.com` and
`script.googleusercontent.com` are deliberately allowlisted for this sync, with a pointer to the
spec — so a future reader does not treat it as an accident.

---

## Self-review

**Spec coverage.** Transport → Task 2. Scope filter → Task 1 (`arl_sheet_in_scope`). Triggers →
Task 4. Column set and Position rule → Task 1. Payload keyed by header name → Task 1 Step 1 plus
Apps Script header mapping in Task 2. Signature as query param → Tasks 2 and 3. Idempotency → Task
2 Step 4 and Task 4 Step 5. Observability (stamp, log, `sheet:status`) → Tasks 3 and 5. Commands →
Task 5. Backfill → Task 7. Testing sequence → Tasks 3 and 4. Staging guard exception → Task 3 Step
1, documented in Task 8 Step 4. Code Snippets not mu-plugin → Task 6. Repo mirror → Task 8.
No gaps.

**Placeholders.** The angle-bracket items (`<the /exec URL>`, `<the secret>`, `<ID>`,
`<new order id>`, `STAGING_DB_PASS=<see compose.yml>`) are values that cannot exist until Cody
deploys the script or a snippet row is created. Every one is accompanied by the step that produces
it. No "TBD", no "add error handling", no code-free steps.

**Name consistency.** `arl_sheet_option`, `arl_sheet_product_ids`, `arl_sheet_columns`,
`arl_sheet_in_scope`, `arl_sheet_position_for_product`, `arl_sheet_build_payload`, `arl_sheet_log`,
`arl_sheet_push_order`, `arl_sheet_enqueue`, action hook `arl_sheet_push`, group `arl-sheet`,
options `arl_sheet_webhook_url` / `arl_sheet_secret` / `arl_sheet_product_ids` /
`arl_sheet_sku_prefix`, meta `_arl_sheet_pushed_at` / `_arl_sheet_row`. Each is defined once in
Task 1, 3, 4 or 5 and used consistently thereafter. The 19 header strings appear in exactly two
places — `arl_sheet_columns()` and the Apps Script `getSheet()` header row — and match.
