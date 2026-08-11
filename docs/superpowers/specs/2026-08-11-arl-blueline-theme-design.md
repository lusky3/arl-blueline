# `blueline` — a standalone WordPress theme for the ARL

**Status:** design approved, ready for implementation planning
**Date:** 2026-08-11
**Supersedes:** `docs/plans/2026-07-27-rookie-sport-theme.md` as a *design*. That plan's
technical research (SportsPress template resolution, WooCommerce override inventory,
escaping/WPCS constraints) remains valid and should be mined; its aesthetic decisions
— dark-sports palette, Barlow Condensed as the only face — are replaced by this document.

---

## 1. What we are building

A standalone WordPress theme, slug and text domain `blueline`, that replaces both
`rookie-child` and its ThemeBoy `rookie` parent on https://www.rookiehockey.ca.

It carries the ARL's new 2026 brand as a real design language, integrates deeply with
SportsPress Pro and WooCommerce, and adds two things the current site cannot do:

1. a homepage that shifts emphasis between "register" and "your next game" on its own, and
2. a My Account area that reads as a **league** account rather than a shop account.

### The site, for a reader with no context

The ARL (Adult Recreational League) is adult co-ed **beginner** ice hockey in Burlington,
Ontario. WooCommerce is not a store in the ordinary sense — it sells one thing, a seasonal
*registration*, which is the league fee. SportsPress Pro runs the sporting side: teams,
players, events, standings, stats.

That framing drives most decisions below. The typical visitor is either a nervous adult who
has never played and is deciding whether to spend ~$500, or an existing player checking
where and when their next game is.

---

## 2. Decisions

| # | Decision | Rationale |
|---|---|---|
| D1 | Fresh design; mine the 2026-07-27 plan for facts only | Its research is expensive to redo and still correct; its aesthetic was decided before the 2026 logo existed |
| D2 | Primary job is **both**, seasonally weighted | Registration windows are the conversion push; the rest of the season the site is a utility |
| D3 | Visual direction **"Blue Line"** — the logo's own graphic language over a light base | Most ownable *and* most welcoming; a dark sports theme is the most-travelled path and is what the site already has |
| D4 | **Standalone** theme, no parent | Removes ThemeBoy's Rookie entirely; the child theme is already nearly empty, so the parent is the only real dependency |
| D5 | Full scope: public pages, registration/checkout, My Account, emails, archived season pages | User decision |
| D6 | **Two releases**: public + My Account first, commerce redesign after the registration window closes | W2026-27 registration is open now; the checkout was debugged in August 2026 and should not be restructured while money is moving through it |
| D7 | Deprecate `yith-woocommerce-customize-myaccount-page` entirely | The account area should be league-first; the plugin enforces a shop-first IA |
| D8 | My Account rebuild ships in **R1** | Not the money path — worst case is an ugly page, not a lost registration — and it is most useful exactly when new players are signing up |
| D9 | Classic PHP theme, **not** block/FSE | SportsPress Pro's widget and template system is not FSE-compatible, and eight SP extensions are in play |

---

## 3. The Blue Line design language

### 3.1 Origin

The brand mark (`ARL2026-Logo-Final`, uploaded 2026-08) is a **blue** maple leaf carrying a
diagonal banner: "BURLINGTON" in small caps above "A.R.L." in heavy italic varsity lettering.
Every device below is derived from it, so the result is not reusable by anyone else.

### 3.2 Colour tokens, with verified contrast

Ratios are computed against the paper ground `#F7FBFC`, not estimated.

| Token | Hex | Ratio on paper | Permitted use |
|---|---|---|---|
| `--ink` | `#132343` | **14.94** | body text, dark bands, footer |
| `--ink-deep` | `#0D1729` | — | hero / footer ground |
| `--ink-mid` | `#2E4A74` | 8.59 | secondary text |
| `--accent-text` | `#3F6E9D` | **5.13** | links and accent text on light |
| `--steel` | `#5188B7` | 3.63 | borders, large text, UI strokes |
| `--ice` | `#74C0E1` | **1.94** | **fills only — never text on light** |
| `--pale` | `#9ACDE7` | 1.64 | decorative; text only on dark (9.09 on `--ink`) |
| `--paper` | `#F7FBFC` | — | page ground |

Two rules fall out of the arithmetic, and both correct the approved mockup:

- **Ice blue is a fill, never light-background text.** At 1.94:1 it fails every threshold.
  As a fill it is excellent: `--ink` on `--ice` is **7.69:1**, so the skewed Register button
  passes comfortably.
- **The active-nav treatment in the mockup was wrong.** White on `--steel` is **3.78:1**,
  which fails AA for nav-sized text. Resolution: white on `--ink` with an `--ice` underline.
  (Navy on `--ice` is the alternative and also passes.)

On dark bands: `--pale` on `--ink` is 9.09, `--ice` on `--ink` is 7.69, paper on `--ink` is
14.94. All comfortable.

Target is **WCAG 2.2 AA** throughout.

### 3.3 Typography

| Role | Face | Notes |
|---|---|---|
| Display | Barlow Condensed 800 italic | Closest free match to the logo's varsity lettering |
| Body / UI / tables | Inter | Tabular figures so standings columns align |

Both **self-hosted**. This removes third-party CDN latency and the GDPR question at once,
and matches the fix already reached in the superseded plan. Fluid scale via `clamp()`.

### 3.4 The four signature devices

1. **The skew** — −11°, matching the logo's banner, on ribbons, primary buttons, active nav
   and section eyebrows. Text is counter-skewed so glyphs stay upright. **Focus rings are
   drawn on the un-skewed parent** so keyboard focus and hit targets stay honest.
2. **Blue-line bands** — a paired 9px `--ice` + 4px `--ink` rule separating major page
   bands, echoing a rink's blue lines.
3. **Faceoff geometry** — concentric rings as large low-opacity art inside dark bands;
   small ring bullets in lists.
4. **The blue leaf** — the logo silhouette as a section watermark and as the empty-state
   and loading mark.

### 3.5 Structure

Navy hero, navigation and footer; paper-white content body. Dark where drama helps, light
where dense SportsPress tables have to stay readable. Spacing on a 4px base scale.

Discipline note: heavy italic caps are the failure mode of this direction. Display italic
is reserved for headings, eyebrows and buttons — never body copy, never table cells.

---

## 4. Architecture

### 4.1 What standalone actually costs

Measured, not estimated:

| Source | Scale | What it means |
|---|---|---|
| `rookie` (parent) | 38 PHP files — 23 top-level templates, 5 SportsPress overrides, 6 `inc/` files | Must be reimplemented; this is the real work |
| `rookie-child` | 30 files — `functions.php` is **15 lines**, `style.css` 963 lines, plus **25 WooCommerce overrides** | Thin. Little logic to preserve; the overrides are mostly relabeling |

The child theme's `functions.php` does one thing: enqueue the parent stylesheet. All site
personality currently lives in 963 lines of CSS overrides and the WooCommerce templates.

### 4.2 Findings that shrink the job

- **`sportspress_enable_frontend_css` is unset**, so it defaults to `no`. SportsPress is not
  emitting its own frontend CSS. **The theme owns all styling outright** — no fighting SP's
  colour system, no `sportspress_frontend_css` colour plumbing to replicate.
- **Widget areas are barely used.** Only `sidebar-1` (SP countdown, recent posts, quotes) and
  `footer-2` (two blocks) have widgets. `header-1`, `homepage-1`, `footer-1` and `footer-3`
  are empty, and no mega-slider or news-widget plugin is installed — so Rookie's
  `mega-slider`, `social-sidebar` and `news-widget` theme supports can be **dropped** rather
  than reimplemented.

### 4.3 What must be carried over

- `add_theme_support( 'sportspress' )` — required for SP template resolution.
- `add_theme_support( 'woocommerce' )`.
- The `sportspress_header_sponsors_selector` filter (header sponsors).
- All 25 WooCommerce overrides from `rookie-child`, including the 14 email templates —
  a standalone theme that omits them reverts customers to stock WooCommerce wording.
- SportsPress template overrides live in `sportspress/`; SP's native `SP_TEMPLATE_PATH`
  and `locate_template()` pick these up from the active theme with no custom filter.

### 4.4 File structure

```
wp-content/themes/blueline/
├── style.css                  theme header + design tokens (:root)
├── functions.php              bootstrap; requires inc/
├── index.php  header.php  footer.php  sidebar.php
├── page.php  single.php  archive.php  search.php  404.php  comments.php
├── content*.php               loop partials
├── template-homepage.php  template-fullwidth.php
├── sportspress.php            root fallback for SP archives
├── rtl.css                    auto-loaded by WP
├── inc/
│   ├── setup.php              theme supports, nav menus, widget areas
│   ├── enqueue.php            scripts + styles
│   ├── template-tags.php
│   ├── customizer.php
│   ├── sportspress.php        SP body classes, sidebar logic
│   ├── woocommerce.php        WC integration (class_exists guarded)
│   ├── season-state.php       §5
│   └── account/               §6 — endpoints, player link, dashboard modules
├── sportspress/               single-event, single-player, single-team,
│                              single-staff, taxonomy-venue, team-lists, index.php
├── woocommerce/               25 overrides ported from rookie-child
├── assets/{src,dist}/         @wordpress/scripts (webpack) build
└── languages/blueline.pot
```

Build artifacts in `assets/dist/`; source in `assets/src/`. No jQuery dependency in new code.

### 4.5 Global constraints

- Never modify core or plugin files.
- All output escaped (`esc_html`, `esc_url`, `esc_attr`, `wp_kses_post`); all input sanitized.
- `$wpdb` always via `prepare()`; capability checks before privileged operations.
- WPCS: `phpcs --standard=WordPress`.
- No inline styles from PHP — custom properties or classes only.
- Guard every SportsPress and WooCommerce touchpoint with `class_exists` / `function_exists`;
  the theme must not fatal if either plugin is deactivated.
- **`get_permalink()` is unreliable on this site** — the Page Links To plugin filters it and
  may return a redirect target. Use `post_name` + `post_parent` for real paths.

---

## 5. Season State

A single module, `inc/season-state.php`, exposing:

```php
arl_season_state(): string  // registration_open | preseason | in_season | playoffs | offseason
```

Derived from data already maintained in the admin, not a new setting to keep in sync:

- a purchasable, in-stock product in the current season's `product_cat` → `registration_open`
- upcoming SportsPress events → `preseason` vs `in_season`
- playoff events / playoff standings → `playoffs`
- none of the above → `offseason`

Cached in a transient, invalidated on product and event save, with a filter
(`blueline_season_state`) so the state can be forced manually.

`template-homepage.php` selects a hero variant and module order from that one value. This is
what makes the site seasonally weighted without anyone editing it twice a year. Deliberately
small: one function, one transient, one filter — not a subsystem.

**Stock-status caveat, carried from `CHANGES-2026-08.md`:** writing `_stock_status` post meta
leaves `wp_wc_product_meta_lookup.stock_status` stale, and that lookup table is what catalog
queries read. Season State must read through the product object (`wc_get_product()`), not the
lookup table, or it will disagree with the catalogue.

---

## 6. My Account — league-first

### 6.1 Goal

Invert the information architecture. Today the account is a shop account with league data
absent. It should be a **player dashboard** with billing demoted to a secondary group.

### 6.2 Deprecating YITH — what it actually provides

`yith-woocommerce-customize-myaccount-page` currently supplies nine endpoints, a sidebar
menu layout, custom avatars and a reCAPTCHA. The account page is post **9328**, slug
`account`.

| Endpoint | Label | **Live slug** | Active |
|---|---|---|---|
| `dashboard` | Dashboard | `/account/dashboard` | yes (default endpoint) |
| `orders` | My Registrations | **`/account/registrations`** | yes |
| `credit` | Credits | **`/account/store-credit`** | yes |
| `refund-requests` | Refund requests | `/account/refund-requests` | yes — content is `[ywcars_refund_requests]` |
| `woo-subscription` | Installments | **`/account/subscriptions`** | yes, but orphaned (§6.6) |
| `payment-methods` | Payment Methods | `/account/payment-methods` | yes |
| `edit-address` / `edit-account` | — | default | yes |
| `downloads` | My Downloads | — | **already disabled** |

**The trap.** The bolded slugs are YITH's renames, not WooCommerce defaults. Deactivating the
plugin turns `/account/registrations` back into `/account/orders`, and every bookmarked or
emailed link 404s.

Mitigation, required on day one:

- re-register identical slugs via `woocommerce_get_query_vars` and
  `woocommerce_account_menu_items`, plus `add_rewrite_endpoint` where needed;
- add 301s from the WooCommerce default slugs to the ARL slugs;
- flush rewrite rules on theme activation — **and note that a rewrite flush on this site
  previously exposed a `/register` 405**, fixed by `mu-plugins/rh-royal-mcp-register-fix.php`.
  Production carries that mu-plugin; verify it is present before flushing.

### 6.3 The constraint: linking a WordPress user to their `sp_player` record

**Corrected during Task 16.** This section originally measured coverage against the wrong
denominator — `sp_current_team`, a *sticky* "last team this player was ever on" field that is
set on 2,047 of 2,134 players (95% of everyone who has ever played, not a current-season
roster). Against that denominator, linkage reads as "only 12%," which drove a false premise
throughout the rest of this spec: that ~88% of logged-in players would see an empty dashboard
and that the pre-launch backfill was the primary fix for that. Both are wrong. The
`sp_current_team` figure is kept below only as a warning against reusing it.

A league account has to know which player you are. That link is `sp_user` post meta on
`sp_player` — the same meta `sportspress-player-registration` writes at checkout, so writing it
here keeps us compatible with the plugin rather than inventing a parallel link. Real season
membership is the `sp_season` **taxonomy**, not `sp_current_team`. Measured live on staging,
2026-08-11:

| Metric | Count |
|---|---|
| `sp_player` posts (published) | 2,134 |
| WP users | 2,423 |
| **W2026-27 players** (`sp_season` = current term) | **90, of which 76 linked → 84%** |
| W2025-26 players (`sp_season` = last full season) | 524, of which 241 linked → 46% |
| `sp_current_team` set (sticky, misleading denominator — do not use) | 2,047, of which 242 linked → 12% |
| Players with a non-empty `spat_email` | 51 |

The real reason coverage is high for the current season: `sportspress-player-registration`
auto-links `sp_user` at checkout, so anyone registering through the live flow is linked
automatically, with no manual step. The unlinked long tail is almost entirely *historical*
players from past seasons who registered before that plugin existed, or who never made an
account at all — W2026-27 is small (90) only because registration opened days ago and is still
filling; its 84% is the number that matters for launch-day dashboard experience. Email is not a
usable fallback join key at 51 records.

**Two-part answer, both in scope — but re-ranked given the real numbers:**

1. **Claim flow for the unlinked state (the primary mechanism, not a supplement).** When
   `sp_user` is missing, the dashboard leads with an "Is this you?" card built from a name
   match; one click confirms and writes `sp_user`. Writes are capability- and nonce-checked, and
   a player already claimed by another user is never offered. This is the real safety net for
   the current season's ~16% gap and for returning players whose historical records predate
   auto-linking.
2. **Pre-launch backfill — a small top-up, not the main mechanism.** A one-off matcher joining
   billing first/last name on recent registration orders against `sp_player` posts in the
   current or immediately-preceding season, emitting a **reviewable list** — no blind writes.
   Task 14 measured its actual yield empirically: of 74 non-guest customers who bought a
   current-season product, 69 were *already linked* before the script ran; the backfill moved
   coverage by only a few players, because auto-linking at checkout had already done almost all
   of the work. Ambiguous matches (duplicate names) are left for manual resolution.

The dashboard must still render sensibly in the unlinked state — league modules are replaced by
the claim card, and the billing group still works — but that state is now the ~16% exception for
a freshly-registered player, not the ~88% default the original framing implied.

### 6.4 Page composition

**League — primary, above the fold**

- **My next game** — date, time, rink **and pad** (the venue distinction matters here:
  Twin Red = term 14, Twin Black = term 13), opponent crest, add-to-calendar.
- **My team** — crest, division, record, teammates, my jersey number (`sp_number`).
- **My season** — GP / G / A / PIM from `sp_statistics`.
- **My registration status** — which season, paid or unpaid, receipt link.

**Account & billing — secondary, grouped below**

Registrations · Store credit · Refund requests · Payment methods · Addresses · Account details.

### 6.5 Layout

YITH's left sidebar is replaced by the Blue Line rail: navy vertical rail on desktop,
horizontally scrollable chips on mobile. Endpoint icons are replaced by the theme's own
set — no Font Awesome dependency.

### 6.6 Migration details

- **Custom avatars — done (Task 13).** 11 entries exist in
  `yith_wcmap_users_avatar_ids`, but that option is not itself a `user_id => attachment_id`
  map — it's YITH's internal upload-bookkeeping list (a sequential push/unset history); the
  real per-user link is user meta `yith-wcmap-avatar` (10 real links; the option's 11th entry
  is an orphan attachment with no owning user). Migrated to theme-owned user meta
  (`blueline_avatar_id`) plus a `pre_get_avatar_data` filter — the one hook that reaches both
  `get_avatar()` and `get_avatar_url()` — verified on staging. **This produces no visible
  change at cutover**: `show_avatars` is off site-wide, and an active Code Snippets rule
  ("Remove Gravatars", ID 23) unconditionally filters `get_avatar` to return an empty string
  for every user. Both predate this project and would have suppressed YITH's own avatar image
  too — these 10 users' avatars may never have actually rendered on the live site. The
  migration is still correct and necessary (it preserves the data and wires the filter for
  whichever future template first calls `get_avatar()`/`get_avatar_url()`), but nobody should
  "verify" it by looking for a visible avatar on `/account` and conclude it failed.
- **reCAPTCHA vs Turnstile — resolved (Task 13).** YITH's reCAPTCHA is the *only* thing that
  has ever guarded the registration form; Turnstile has never covered it, despite already
  being installed and credentialed on production. See §9 Risks for the verified state and the
  required pre-cutover step.
- **The "Installments" endpoint is already dead.** No subscriptions plugin is active, yet 534
  `shop_subscription` records exist — 2 `wc-active`, 127 `wc-on-hold`, 66 cancelled, 338
  expired, plus 1 `hf_shop_subscription` and 3 `ywsbs_subscription`. The endpoint cannot
  render today, so dropping it from the theme is safe. **Those 2 active subscriptions are a
  business question for the league, not a theme question** — flagged, not resolved here.
- **`refund-requests`** renders the `[ywcars_refund_requests]` shortcode from
  `yith-advanced-refund-system-for-woocommerce`, which **stays installed**. Only the
  my-account-page plugin is removed. The theme calls the shortcode or the plugin API directly,
  keeping `rookie-child`'s existing "Reg #" relabeling.

---

## 7. Scope and releases

### R1 — public site + My Account

- Every core and SportsPress template designed in the Blue Line language.
- Season State and the season-aware homepage.
- **My Account rebuilt league-first; YITH deprecated**, including endpoint slugs, 301s,
  avatar migration and the `sp_user` backfill + claim flow.
- The 25 WooCommerce templates ported from `rookie-child` **1:1, restyled with tokens only —
  no markup changes to cart or checkout.**
- The ~20 archived season pages audited: each confirmed to render under the new page
  template with no dependency on Rookie-specific markup or classes, and any that do
  depend on it corrected.

**R1 is itself large enough to be its own implementation plan.** The plan produced from this
spec covers R1 only; R2 gets a separate plan written once the registration window closes and
the plugin-ownership questions in §9 are settled.

### R2 — commerce, after the W2026-27 window closes

- Product archive and single-product (registration) redesign.
- Cart, checkout and thank-you redesign.
- Email template redesign.
- Resolve plugin-vs-theme ownership on the commerce path (§9).

The split exists so the checkout debugged in August 2026 stays structurally untouched while
registration is live.

---

## 8. Verification and cutover

Built and verified on **`staging.rookiehockey.ca`** (host `staging-host`, Docker Compose at
`/opt/staging`, no page-cache layer) against a production clone. The staging guard
(`00-staging-guard.php`) must be verified present *before* any import — it blocks outbound
mail, push and third-party APIs, and it is file-based so it survives a database import.
`mu-plugins` are not covered by the standard plugins/themes sync and must be copied
separately, without `--delete`.

**Gates before cutover:**

| Gate | How |
|---|---|
| Registration flow unbroken | `staging/tests/repro-duplicate-registration.sh` and `test-one-registration-guard.sh` pass |
| Checkout completes | `staging/tests/checkout-end-to-end.py` against the offline `betpg` gateway |
| Account endpoints | every ARL slug resolves; every WooCommerce default slug 301s to it |
| Player link | backfill reviewed and applied; claim flow works for an unlinked user |
| Accessibility | keyboard traversal, visible focus on skewed controls, contrast tokens, `prefers-reduced-motion` |
| Archives | all ~20 past-season pages render |
| SP entity pages | event, player, team, staff, venue |

**Cutover:** switch the active theme, then purge the Redis-backed nginx srcache **by key** —
`wo clean --fastcgi` does not touch it:

```
redis-cli DEL 'nginx-cache:httpsGETwww.rookiehockey.ca/' …
```

Appending a junk query string bypasses srcache and shows what WordPress actually rendered.

---

## 9. Risks

| Risk | Mitigation |
|---|---|
| **Account URL slugs break on YITH removal** | Re-register identical slugs + 301s from defaults; test every endpoint before cutover (§6.2) |
| **A freshly-registered or historical player has no `sp_user` link yet → empty dashboard** — corrected in Task 16: real current-season (W2026-27) coverage is 84% (76/90), not the 12% the spec originally measured against the wrong (`sp_current_team`) denominator; the claim flow is the primary mechanism for the remaining ~16%, the backfill only a small top-up (§6.3) | Claim flow + pre-launch backfill; dashboard degrades gracefully (§6.3) |
| **Plugin/theme overlap on the commerce path** — `woocommerce-checkout-field-editor-pro` (~16 custom checkout fields), `wp-email-template`, `woocommerce-store-credit`, `yith-advanced-refund-system` all render into templates the theme also overrides | R1 does not restructure these. R2 establishes ownership per template before redesigning |
| **The skew device as an accessibility liability** | Counter-skewed text, focus rings on un-skewed parents, explicit audit gate |
| **Rewrite flush exposing the `/register` 405** | Confirm `rh-royal-mcp-register-fix.php` is present in production `mu-plugins` before flushing |
| **Losing customer-facing email wording** | All 14 email templates ported in R1, before any redesign |
| Custom avatars lost silently | Migrate the 11 records (§6.6) |
| **Registration form loses bot protection when YITH is removed — resolved with a required pre-cutover step.** Verified via a live `$wp_filter` dump on staging (YITH deactivated there): `woocommerce_login_form` and `login_form` have never had any callback from either plugin — login has never been guarded. `woocommerce_register_form` is currently guarded only by WooCommerce core's own non-security hooks. Reading YITH's source confirms its reCAPTCHA (`yith-wcmap-enable-recaptcha`, genuinely configured with real keys) only ever hooked `woocommerce_register_form` — it is the *only* bot protection the registration form has ever had. `simple-cloudflare-turnstile` is **active on production**, already credentialed (`cfturnstile_key`/`cfturnstile_secret`/`cfturnstile_tested` all set) and already guarding Gravity Forms (`cfturnstile_gravity` on) — but every WooCommerce toggle is off: `cfturnstile_woo_register`, `cfturnstile_woo_login`, `cfturnstile_woo_checkout`, `cfturnstile_woo_reset` all `off`. So Turnstile does **not** currently cover this form, contrary to this spec's original assumption in §6.6 — but closing the gap is a **settings change, not a new integration**, since the plugin, keys, and Gravity Forms precedent already exist | **Before YITH is deactivated on production:** enable `cfturnstile_woo_register` (registration parity with today); consider also enabling `cfturnstile_woo_login`, since login has never been protected by either plugin and this is a good moment to add it; then load `/account?action=register` and confirm the Turnstile widget actually renders and validates before removing YITH |
| `/registration/*` child pages 404 | Pre-existing: WooCommerce `product_base` is `/registration`, shadowing children of page 168. Not caused by, and not fixed by, the theme |
| **`.sp-league-table` sticky headers conflict with the horizontal-scroll hard constraint** — `border-collapse: collapse` spec-disables `position: sticky` on table-part boxes, and separately, the scroll wrapper's own `overflow-x: auto` forces its `overflow-y` to also become a scroll container (an unavoidable CSS pairing rule), leaving the sticky header nothing but that wrapper's own zero-spare-height box to stick within — confirmed live, both causes independently verified (Task 8 fix round 2) | **Ruled out, not implemented.** Divisions run ~8 teams, so standings tables are short; sticky buys little on tables this size, and the only CSS-only fix (bound the wrapper's `max-height` so it becomes its own vertical scroll region, then sticky the header inside that) trades an inline full-height table for a boxed one with an internal scrollbar — a real UX cost on `/standings`, the site's most-visited page. A working sticky header would cost a JS-driven frozen-header rewrite (splitting header from horizontally-scrolling body, syncing scroll position) — not attempted in R1 |

---

## 10. Open items

*(Former item 1, "which service guards the account form," is resolved — see §9 Risks.)*

1. **The 2 active `shop_subscription` records** — a league business decision about payment
   plans, referred to the ARL.
2. **Theme slug** — `blueline` is assumed throughout; trivially changeable before scaffolding.

---

## 11. Reference — verified environment facts

Gathered 2026-08-10/11 against the live site; recorded so implementation does not re-derive them.

- WordPress 6.9.6 · WooCommerce 11.0.0 · SportsPress Pro 2.7.26 · PHP 8.3 · `en_CA` ·
  `America/Toronto`
- Active theme `rookie-child` 1.0.0 over `rookie` 1.5.4 (ThemeBoy)
- Host `production-host` (`production-host.example:SSH_PORT`), WordOps + nginx + MariaDB + Redis; web root
  `/var/www/rookiehockey.ca/htdocs`; DB prefix `wp_`
- SportsPress extensions active: pro, for-ice-hockey, player-registration, player-tools,
  admin-tools, events-manager, etransfer-automation, yoast-seo-for-sportspress
- Commerce-adjacent plugins: checkout-field-editor-pro, store-credit,
  yith-advanced-refund-system, yith-customize-myaccount-page *(to be removed)*,
  follow-up-emails, wp-email-template, paypal-for-woocommerce, email-money-transfer-gateway
- Division term IDs: 8 = D1, 7 = D2, 6 = D3, 5 = D4, 2 = D5. Venue terms: 14 = Twin Red,
  13 = Twin Black
- Widgets in use: `sidebar-1` = SP countdown, recent posts, quotes-llama;
  `footer-2` = two blocks. All other areas empty
- Nav menu in use: "Menu 3.0" (ID 612), assigned to `primary`
