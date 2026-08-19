# Order → Google Sheet sync — design

**Date:** 2026-08-14
**Status:** design agreed, not yet implemented

## Goal

Mirror each Winter 2026-27 registration order into a Google Sheet as one row, carrying the same
columns the WooCommerce Order Export plugin emits, and keep that row current — order status
changes and admin edits to the player questionnaire update the existing row rather than adding a
new one. This is the thing the export plugin cannot do: it produces point-in-time files, not a
live mirror.

## Decisions locked during brainstorming

| Decision | Choice |
|---|---|
| Transport | Google Apps Script web app bound to the sheet; WordPress POSTs JSON with a shared secret |
| Scope | Current season only — W2026-27 Player (116522) and Goalie (116523), plus any W2026-27 waitlist product added later |
| Triggers | New order, order status change, and admin edits to order meta |
| Code location | Code Snippets, not an mu-plugin |
| Upsert key | Order ID in column A |

### Why Apps Script rather than the Sheets API

No Google client library is installed, so the Sheets API would mean hand-rolled JWT signing, token
refresh, and a service-account private key stored on the server. Apps Script needs only a URL and
a shared secret, and the row-matching logic lives in the sheet where it can be inspected and
adjusted without a WordPress deploy.

### Why Code Snippets rather than an mu-plugin

The trigger hooks run during checkout. A fatal error in an mu-plugin white-screens the site
including checkout, during open registration. Code Snippets traps fatals and deactivates the
offending snippet, so the worst case is the sync stopping quietly while the site keeps taking
registrations. That is the correct failure direction. It also matches snippets 52–55 and is
already mirrored to this repo by `scripts/sync-snippets.sh`.

Because silent deactivation is the accepted failure mode, observability is a requirement, not a
nicety — see *Observability* below.

## Column set — 19 columns, A–S

Column A is new: the export plugin's field set has no stable key, and one is needed to upsert.
Columns B–P are the export plugin's existing 15 fields **in their existing order**, so the sheet
stays directly comparable to an export file. Q–S are the additions requested.

| Col | Header | Source |
|---|---|---|
| A | Order ID | order ID — **upsert key** |
| B | Order Date | `post_date`, formatted `Y-m-d H:i` |
| C | Product Name | name of the registration line item |
| D | Order Status | `wc_get_order_status_name()` (human label, e.g. "Completed") |
| E | First Name | `billing_first_name` |
| F | Last Name | `billing_last_name` |
| G | Email | `billing_email` |
| H | Gender | `arl_gender` |
| I | D.o.B. | `arl_dob` |
| J | Position | **derived — "Player" or "Goalie"**, see below |
| K | Experience | `arl_experience` |
| L | Division | `arl_division` |
| M | Returning Player | `arl_returning` |
| N | Restricted | `arl_waitlist_restrictions` |
| O | Requested Team | `arl_team` |
| P | Requested Partner | `arl_request` |
| Q | Captain | `arl_captain` |
| R | Requested Partner 2 | `arl_request2` |
| S | Requested Partner 3 | `arl_request3` |

### Position derivation

"Position" means **Player or Goalie**, not the hockey position. It is derived from the product on
the registration line item, in this order:

1. `product_tag` term **201** → `Player`; term **202** → `Goalie`. Both current and previous
   season products carry these tags.
2. Fallback on SKU suffix: `-P` → `Player`, `-G` → `Goalie`, `-WP` → `Waitlist Player`.
3. If neither resolves, write the empty string and log a warning naming the product ID.

If an order somehow contains both a player and a goalie line, join the values with `" + "`. No
W2026-27 order currently does, and snippet 53's one-registration-per-order guard prevents new ones.

### Fields deliberately not included

- `arl_position` (values "Any", "Defense", "RW", "C", "LW") — not collected this season, and the
  Position column now means Player/Goalie.
- `arl_relation`, `arl_relation2`, `arl_relation3` — the relationship to each requested partner
  (e.g. "Parent", "Other"). Not in the export field set and not requested. Non-empty on 159 / 25 / 4
  of 241 orders respectively, so they exist if wanted later; adding them is three more columns.
- `arl_jerseysize`, `arl_tradeclause`, `arl_emergency_contact`, `arl_emergency_number` — not in the
  export field set. Emergency contact details are deliberately left out of a shared spreadsheet.

`Restricted` (column N) will be blank all season: `arl_waitlist_restrictions` is waitlist-only and
there is no W2026-27 waitlist product yet. It is kept for parity with the export file.

## Architecture

### 1. Triggers (Code Snippets, front-end + admin scope)

Three hooks, each doing nothing but enqueueing:

```php
add_action( 'woocommerce_new_order',                'arl_sheet_enqueue' );
add_action( 'woocommerce_order_status_changed',     'arl_sheet_enqueue' );
add_action( 'woocommerce_process_shop_order_meta',  'arl_sheet_enqueue', 99 );
```

`arl_sheet_enqueue( $order_id )`:

1. Return unless the order contains a W2026-27 registration product (`116522`, `116523`, or a
   product in `product_cat` 91 whose SKU begins `116522-`). Scope check lives here so the worker
   never has to care.
2. Return if an unrun `arl_sheet_push` action already exists for this order ID — collapses a burst
   of edits into one push. Use `as_has_scheduled_action( 'arl_sheet_push', [ $order_id ] )`.
3. `as_enqueue_async_action( 'arl_sheet_push', [ $order_id ], 'arl-sheet' )`.

`woocommerce_process_shop_order_meta` fires at priority 99 so Checkout Field Editor Pro has already
written its fields when we read them.

### 2. Worker (same snippet)

`add_action( 'arl_sheet_push', 'arl_sheet_push_order' );`

1. Load the order; bail if it no longer exists.
2. Build the 19-value payload.
3. `wp_remote_post()` to the Apps Script `/exec` URL, JSON body, 15-second timeout.
4. Authenticate with `?sig=<hmac_sha256(body, secret)>` appended to the URL. An HMAC over the raw
   body rather than a bare secret, so the secret itself is never transmitted.
   **Not a header:** Apps Script's `doPost(e)` exposes no request headers, so the signature must
   travel as a query parameter. Corrected 2026-08-14 while writing the implementation plan.
5. On HTTP 200 with `{"ok":true}`: stamp `_arl_sheet_pushed_at` and `_arl_sheet_row` on the order.
6. On anything else: log, and throw an exception so Action Scheduler retries with backoff. Action
   Scheduler is already running on this site (2,671 rows) driven by system cron, since
   `DISABLE_WP_CRON` is true.

### 3. Apps Script (bound to the sheet)

`doPost(e)`:

1. Verify the HMAC. Reject with 401 on mismatch — the `/exec` URL is otherwise world-reachable.
2. Read column A into a map of Order ID → row number, once per invocation.
3. If the order ID is present, overwrite that row's A:S. If not, append.
4. Return `{"ok":true,"row":N}`.
5. Use `LockService` for the duration, so two near-simultaneous pushes cannot both append the same
   order.

Row 1 is a frozen header row written from the same column list, so a fresh sheet is self-describing.

## Payload

```json
{
  "order_id": 116777,
  "values": {
    "Order ID": "116777",
    "Order Date": "2026-08-13 13:11",
    "Product Name": "Player Registration (W2026-27)",
    "Order Status": "Completed",
    "First Name": "Richard",
    "Last Name": "Peters",
    "Email": "player@example.com",
    "Gender": "Male",
    "D.o.B.": "1986-02-07",
    "Position": "Player",
    "Experience": "5 - Beginner",
    "Division": "5 - Beginner",
    "Returning Player": "Returning",
    "Restricted": "",
    "Requested Team": "",
    "Requested Partner": "Andrew McRorie",
    "Captain": "No",
    "Requested Partner 2": "",
    "Requested Partner 3": ""
  }
}
```

Keyed by header name rather than array position, so inserting a column later does not silently
shift data into the wrong cells. The Apps Script maps names to columns using row 1.

## Observability

Because a fatal deactivates the snippet silently, three things make that detectable:

1. **Per-order stamp** `_arl_sheet_pushed_at`. Drift is then one query — registration orders
   created more than 15 minutes ago with no stamp, or with a stamp older than `post_modified`.
2. **A log file** at `wp-content/arl-sheet-sync.log`: timestamp, order ID, HTTP code, and the
   script's response. No PII beyond the order ID.
3. **`wp arl sheet:status`** — prints pushed vs unpushed counts for the season and the last 10 log
   lines, so one command answers "is this still working".

## Commands

- `wp arl sheet:sync <order_id>` — push one order now, synchronously, printing the response.
- `wp arl sheet:sync --season=W2026-27 [--force]` — batched backfill, 50 orders per batch with a
  pause between batches. Without `--force`, skips orders that already have a stamp newer than
  `post_modified`.
- `wp arl sheet:status` — as above.

## Backfill

241 W2026-27 orders exist. Backfill runs **after** live pushes are confirmed working, via
`wp arl sheet:sync --season=W2026-27`. Because the sheet upserts on order ID, running it twice is
harmless — which is what makes the backfill safe to retry.

## Testing

1. **Staging first.** Point the snippet at a throwaway sheet. The staging guard blocks outbound
   HTTP, so `script.google.com` must be added to the guard's allowlist **on staging only** — a
   deliberate, documented exception, since the whole point of that guard is that nothing escapes.
2. Place a test registration through staging checkout; confirm one row appears.
3. Change its status on staging; confirm the Status cell updates and **no second row appears** —
   this is the core requirement and the thing most likely to be wrong.
4. Edit a questionnaire field; confirm that cell updates.
5. Push the same order twice; confirm still one row.
6. Only then deploy to production against the real sheet, verify with one live order, and backfill.

## What Cody provides

Google resources cannot be created from here — there are no Google credentials on this machine or
the server.

1. Create the spreadsheet (suggested name "ARL - Winter 2026-27 Registrations").
2. Paste in the Apps Script (written as part of implementation) via Extensions → Apps Script.
3. Deploy → New deployment → Web app, execute as yourself, access "Anyone".
4. Provide the `/exec` URL and a secret string of your choosing.

The URL and secret are stored as WordPress options, not hardcoded in the snippet, so the snippet
can be mirrored to this repo without leaking either.

## Out of scope

- Reading edits back **from** the sheet into WooCommerce. One direction only; the sheet is a
  mirror, not a source of truth.
- Deleting rows when an order is trashed. A trashed order's status pushes as "Trash" so the row
  remains visible rather than vanishing silently.
- Next season's rollover. The product IDs in the scope check are season-specific by design; rolling
  over means a new sheet and updating one option.
